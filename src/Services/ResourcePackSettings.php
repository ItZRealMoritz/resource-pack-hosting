<?php

namespace ItZRealMoritz\ResourcePackHosting\Services;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use ItZRealMoritz\ResourcePackHosting\Models\ResourcePackSetting;
use Throwable;

class ResourcePackSettings
{
    private const CACHE_KEY = 'resource-pack-hosting:settings';

    private const MINECRAFT_KEYWORDS = [
        'minecraft', 'paper', 'spigot', 'bukkit', 'purpur', 'folia', 'pufferfish', 'forge', 'neoforge',
        'fabric', 'quilt', 'sponge', 'velocity', 'bungeecord', 'waterfall', 'mohist', 'arclight', 'vanilla',
    ];

    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'configured' => false,
        'egg_ids' => [],
        'auto_detect_minecraft' => true,
        'quota_gb' => 1.0,
        'minecraft_clients_only' => true,
    ];

    /** @return array{configured: bool, egg_ids: int[], auto_detect_minecraft: bool, quota_gb: float, minecraft_clients_only: bool} */
    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                if (!Schema::hasTable('resource_pack_settings')) {
                    return [];
                }

                return ResourcePackSetting::query()
                    ->pluck('value', 'key')
                    ->map(fn ($value) => json_decode((string) $value, true))
                    ->all();
            } catch (Throwable) {
                return [];
            }
        });

        $settings = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));

        return [
            'configured' => (bool) $settings['configured'],
            'egg_ids' => array_values(array_map('intval', (array) $settings['egg_ids'])),
            'auto_detect_minecraft' => (bool) $settings['auto_detect_minecraft'],
            'quota_gb' => max(0.0, (float) $settings['quota_gb']),
            'minecraft_clients_only' => (bool) $settings['minecraft_clients_only'],
        ];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param  array<string, mixed>  $values */
    public function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            ResourcePackSetting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    public function isConfigured(): bool
    {
        return $this->get('configured');
    }

    public function getQuotaBytes(): int
    {
        return (int) round($this->get('quota_gb') * 1024 * 1024 * 1024);
    }

    public static function isMinecraftEgg(Egg $egg): bool
    {
        $tags = array_map('strtolower', (array) ($egg->tags ?? []));
        if (in_array('minecraft', $tags, true)) {
            return true;
        }

        // Pelican's official Minecraft eggs all ship the EULA feature, even when the tag is missing.
        $features = array_map('strtolower', (array) ($egg->features ?? []));
        if (in_array('eula', $features, true)) {
            return true;
        }

        $haystack = strtolower($egg->name . ' ' . $egg->description . ' ' . $egg->author);
        foreach (self::MINECRAFT_KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $haystack)) {
                return true;
            }
        }

        return false;
    }

    public function isEnabledForEgg(Egg $egg): bool
    {
        if (in_array($egg->id, $this->get('egg_ids'), true)) {
            return true;
        }

        return $this->get('auto_detect_minecraft') && self::isMinecraftEgg($egg);
    }

    public function isEnabledForServer(Server $server): bool
    {
        return $server->egg && $this->isEnabledForEgg($server->egg);
    }
}
