<?php

namespace ItZRealMoritz\ResourcePackHosting\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ItZRealMoritz\ResourcePackHosting\Exceptions\InvalidPackException;
use ItZRealMoritz\ResourcePackHosting\Models\ResourcePack;

class ResourcePackService
{
    public const DISK = 'local';

    public function __construct(
        private readonly PackValidator $validator,
        private readonly ResourcePackSettings $settings,
    ) {}

    public function getUsedBytes(Server $server): int
    {
        return (int) ResourcePack::query()->where('server_id', $server->id)->sum('size');
    }

    public function getRemainingBytes(Server $server): int
    {
        return max(0, $this->settings->getQuotaBytes() - $this->getUsedBytes($server));
    }

    /** @throws InvalidPackException */
    public function store(Server $server, string $uploadedPath, string $originalName, ?string $displayName = null): ResourcePack
    {
        $result = $this->validator->sanitize($uploadedPath);

        try {
            return DB::transaction(function () use ($server, $result, $originalName, $displayName) {
                // Lock the server row so two parallel uploads can't both squeeze past the quota.
                Server::query()->whereKey($server->id)->lockForUpdate()->first();

                if ($result['size'] > $this->getRemainingBytes($server)) {
                    throw new InvalidPackException(trans('resource-pack-hosting::strings.errors.quota_exceeded', [
                        'size' => self::formatBytes($result['size']),
                        'remaining' => self::formatBytes($this->getRemainingBytes($server)),
                    ]));
                }

                $pack = new ResourcePack([
                    'uuid' => Str::uuid()->toString(),
                    'server_id' => $server->id,
                    'name' => Str::limit(trim($displayName ?: pathinfo($originalName, PATHINFO_FILENAME)) ?: 'Resource Pack', 100, ''),
                    'original_name' => Str::limit(basename($originalName), 250, ''),
                    'size' => $result['size'],
                    'sha1' => $result['sha1'],
                    'token' => self::generateToken(),
                    'pack_format' => $result['pack_format'],
                    'description' => $result['description'],
                    'file_count' => $result['file_count'],
                ]);
                $pack->setRelation('server', $server);

                $storagePath = $pack->getStoragePath();
                Storage::disk(self::DISK)->putFileAs(
                    dirname($storagePath),
                    new HttpFile($result['path']),
                    basename($storagePath),
                );

                $pack->save();

                return $pack;
            });
        } finally {
            $this->validator->cleanup($result['path']);
        }
    }

    public function delete(ResourcePack $pack): void
    {
        Storage::disk(self::DISK)->delete($pack->getStoragePath());

        $pack->delete();
    }

    public function deleteAllForServer(Server $server): void
    {
        Storage::disk(self::DISK)->deleteDirectory('resource-packs/' . $server->uuid);
    }

    public function regenerateLink(ResourcePack $pack): void
    {
        $pack->update(['token' => self::generateToken()]);
    }

    /**
     * Writes resource-pack, resource-pack-sha1 and require-resource-pack into server.properties.
     *
     * @throws \Exception
     */
    public function applyToServerProperties(ResourcePack $pack, bool $required): void
    {
        $repository = app(DaemonFileRepository::class)->setServer($pack->server);

        $content = $repository->getContent('server.properties', 1024 * 1024);

        $values = [
            'resource-pack' => str_replace(':', '\\:', $pack->getDownloadUrl()),
            'resource-pack-sha1' => $pack->sha1,
            'require-resource-pack' => $required ? 'true' : 'false',
        ];

        $lines = preg_split('/\r\n|\n/', $content) ?: [];
        foreach ($lines as $i => $line) {
            $key = trim(explode('=', $line, 2)[0]);
            if (array_key_exists($key, $values)) {
                $lines[$i] = $key . '=' . $values[$key];
                unset($values[$key]);
            }
        }

        foreach ($values as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        $repository->putContent('server.properties', implode("\n", $lines));
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
    }
}
