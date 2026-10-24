<?php

namespace ItZRealMoritz\ResourcePackHosting\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ItZRealMoritz\ResourcePackHosting\Exceptions\InvalidPackException;
use ZipArchive;

/**
 * Validates an uploaded resource pack and rebuilds it as a clean ZIP.
 *
 * Only files that a Minecraft resource pack can actually use are copied over, so executables,
 * scripts, symlinks, nested archives, ZIP comments and anything appended to the archive are
 * dropped before the pack is ever served to a player.
 */
class PackValidator
{
    /** @var string[] */
    private const TEXT_EXTENSIONS = [
        'json', 'mcmeta', 'txt', 'properties', 'lang', 'fsh', 'vsh', 'glsl', 'jem', 'jpm', 'md', 'hex', 'csv',
    ];

    /** @var string[] */
    private const ALLOWED_EXTENSIONS = [
        'png', 'ogg', 'ttf', 'otf', ...self::TEXT_EXTENSIONS,
    ];

    /** @var string[] Files commonly added by operating systems that are silently left out. */
    private const JUNK_FILES = ['.ds_store', 'thumbs.db', 'desktop.ini'];

    /** @var array<string, string> Magic bytes of executable or archive formats that are never allowed. */
    private const FORBIDDEN_SIGNATURES = [
        "MZ" => 'Windows executable',
        "\x7FELF" => 'Linux executable',
        "#!" => 'script',
        "PK\x03\x04" => 'nested archive',
        "\xCA\xFE\xBA\xBE" => 'Java class / Mach-O',
        "\xCF\xFA\xED\xFE" => 'Mach-O executable',
        "\xFE\xED\xFA\xCE" => 'Mach-O executable',
        "Rar!" => 'nested archive',
        "7z\xBC\xAF" => 'nested archive',
    ];

    /**
     * @return array{path: string, size: int, sha1: string, pack_format: ?int, description: ?string, file_count: int}
     *
     * @throws InvalidPackException
     */
    public function sanitize(string $sourcePath): array
    {
        $maxEntries = (int) config('resource-pack-hosting.max_entries', 30000);
        $maxUncompressed = (int) config('resource-pack-hosting.max_uncompressed_mb', 1024) * 1024 * 1024;
        $maxRatio = (int) config('resource-pack-hosting.max_compression_ratio', 250);

        $zip = new ZipArchive();
        if ($zip->open($sourcePath, ZipArchive::RDONLY) !== true) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.not_a_zip'));
        }

        $workDir = storage_path('app/private/resource-packs/tmp/' . Str::uuid()->toString());
        File::ensureDirectoryExists($workDir . '/files');

        try {
            if ($zip->numFiles === 0) {
                throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.empty'));
            }

            if ($zip->numFiles > $maxEntries) {
                throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.too_many_files', ['max' => $maxEntries]));
            }

            $entries = $this->collectEntries($zip);
            $prefix = $this->detectRootPrefix(array_keys($entries));

            $totalSize = 0;
            $written = [];
            $mcmeta = null;

            foreach ($entries as $name => $index) {
                $relative = substr($name, strlen($prefix));
                if ($relative === '' || str_ends_with($relative, '/')) {
                    continue;
                }

                $basename = strtolower(basename($relative));
                if (in_array($basename, self::JUNK_FILES, true) || str_starts_with($relative, '__MACOSX/')) {
                    continue;
                }

                $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                    throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.forbidden_file', ['file' => $relative]));
                }

                $stat = $zip->statIndex($index);
                $declaredSize = (int) $stat['size'];
                $compressedSize = (int) $stat['comp_size'];

                if ($declaredSize > 1024 * 1024 && $compressedSize > 0 && intdiv($declaredSize, $compressedSize) > $maxRatio) {
                    throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.zip_bomb', ['file' => $relative]));
                }

                $totalSize += $declaredSize;
                if ($totalSize > $maxUncompressed) {
                    throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.too_large_uncompressed', ['max' => (int) ($maxUncompressed / 1024 / 1024)]));
                }

                $content = $this->readEntry($zip, $index, $declaredSize, $relative);
                $this->assertSafeContent($content, $extension, $relative);

                if ($relative === 'pack.mcmeta') {
                    $mcmeta = $content;
                }

                $target = $workDir . '/files/' . $relative;
                File::ensureDirectoryExists(dirname($target));
                file_put_contents($target, $content);
                $written[$relative] = $target;

                unset($content);
            }

            if ($mcmeta === null) {
                throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.missing_mcmeta'));
            }

            $meta = $this->parseMcmeta($mcmeta);

            $outputPath = $workDir . '/pack.zip';
            $this->buildArchive($outputPath, $written);

            return [
                'path' => $outputPath,
                'size' => (int) filesize($outputPath),
                'sha1' => (string) sha1_file($outputPath),
                'pack_format' => $meta['pack_format'],
                'description' => $meta['description'],
                'file_count' => count($written),
            ];
        } catch (InvalidPackException $exception) {
            File::deleteDirectory($workDir);

            throw $exception;
        } finally {
            $zip->close();
            File::deleteDirectory($workDir . '/files');
        }
    }

    public function cleanup(string $outputPath): void
    {
        File::deleteDirectory(dirname($outputPath));
    }

    /**
     * @return array<string, int> entry name => index
     *
     * @throws InvalidPackException
     */
    private function collectEntries(ZipArchive $zip): array
    {
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i, ZipArchive::FL_UNCHANGED);
            if ($name === false) {
                throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.corrupt'));
            }

            $this->assertSafePath($name);

            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) {
                    throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.symlink', ['file' => $name]));
                }
            }

            if (isset($entries[$name])) {
                throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.duplicate', ['file' => $name]));
            }

            $entries[$name] = $i;
        }

        return $entries;
    }

    /** @throws InvalidPackException */
    private function assertSafePath(string $name): void
    {
        $unsafe = $name === ''
            || str_contains($name, "\0")
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[a-zA-Z]:/', $name)
            || preg_match('/[\x00-\x1F\x7F]/', $name)
            || in_array('..', explode('/', $name), true)
            || strlen($name) > 512;

        if ($unsafe) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.unsafe_path', ['file' => Str::limit(preg_replace('/[^\x20-\x7E]/', '?', $name), 80)]));
        }
    }

    /**
     * Packs are often zipped together with their folder, so a single top-level folder that holds
     * the pack.mcmeta is unwrapped instead of rejecting the upload.
     *
     * @param  string[]  $names
     */
    private function detectRootPrefix(array $names): string
    {
        if (in_array('pack.mcmeta', $names, true)) {
            return '';
        }

        $relevant = array_filter($names, fn (string $name) => !str_starts_with($name, '__MACOSX/'));
        $roots = array_unique(array_map(fn (string $name) => explode('/', $name, 2)[0], $relevant));

        if (count($roots) === 1) {
            $prefix = reset($roots) . '/';
            if (in_array($prefix . 'pack.mcmeta', $names, true)) {
                return $prefix;
            }
        }

        return '';
    }

    /** @throws InvalidPackException */
    private function readEntry(ZipArchive $zip, int $index, int $declaredSize, string $name): string
    {
        $stream = $zip->getStreamIndex($index);
        if ($stream === false) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.corrupt'));
        }

        // Never trust the size from the ZIP header: read at most one byte more than declared.
        $content = stream_get_contents($stream, $declaredSize + 1);
        fclose($stream);

        if ($content === false || strlen($content) !== $declaredSize) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.size_mismatch', ['file' => $name]));
        }

        return $content;
    }

    /** @throws InvalidPackException */
    private function assertSafeContent(string $content, string $extension, string $name): void
    {
        foreach (self::FORBIDDEN_SIGNATURES as $signature => $type) {
            if (str_starts_with($content, $signature)) {
                throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.disguised_file', ['file' => $name, 'type' => $type]));
            }
        }

        $valid = match ($extension) {
            'png' => str_starts_with($content, "\x89PNG\r\n\x1A\n"),
            'ogg' => str_starts_with($content, 'OggS'),
            'ttf', 'otf' => str_starts_with($content, "\x00\x01\x00\x00") || str_starts_with($content, 'OTTO') || str_starts_with($content, 'true'),
            default => !str_contains($content, "\0") && mb_check_encoding($content, 'UTF-8'),
        };

        if (!$valid) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.invalid_content', ['file' => $name]));
        }
    }

    /**
     * @return array{pack_format: ?int, description: ?string}
     *
     * @throws InvalidPackException
     */
    private function parseMcmeta(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['pack']) || !is_array($data['pack'])) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.invalid_mcmeta'));
        }

        $pack = $data['pack'];

        // Since 1.21.9 packs may declare "min_format"/"max_format" instead of "pack_format".
        $format = $pack['pack_format'] ?? $pack['min_format'] ?? null;
        if (is_array($format)) {
            $format = $format[0] ?? null;
        }

        if (!is_int($format) || $format < 1) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.invalid_pack_format'));
        }

        return [
            'pack_format' => $format,
            'description' => $this->flattenDescription($pack['description'] ?? null),
        ];
    }

    private function flattenDescription(mixed $description): ?string
    {
        $text = match (true) {
            is_string($description) => $description,
            is_array($description) && array_is_list($description) => implode('', array_map(fn ($part) => $this->flattenDescription($part) ?? '', $description)),
            is_array($description) => ($description['text'] ?? '') . $this->flattenDescription($description['extra'] ?? null),
            default => null,
        };

        if ($text === null) {
            return null;
        }

        $text = trim(preg_replace('/§./u', '', strip_tags($text)) ?? '');

        return $text === '' ? null : Str::limit($text, 500);
    }

    /**
     * @param  array<string, string>  $files  relative name => absolute path
     *
     * @throws InvalidPackException
     */
    private function buildArchive(string $outputPath, array $files): void
    {
        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.write_failed'));
        }

        ksort($files);
        foreach ($files as $relative => $path) {
            $zip->addFile($path, $relative);
            $zip->setCompressionName($relative, ZipArchive::CM_DEFLATE);
            // Fixed timestamps keep the SHA-1 stable when the same pack is uploaded again.
            $zip->setMtimeName($relative, 315532800);
        }

        if (!$zip->close()) {
            throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.write_failed'));
        }
    }
}
