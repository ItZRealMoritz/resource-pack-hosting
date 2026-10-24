<?php

namespace ItZRealMoritz\ResourcePackHosting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use ItZRealMoritz\ResourcePackHosting\Models\ResourcePack;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackService;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackSettings;
use Symfony\Component\HttpFoundation\Response;

class ResourcePackDownloadController extends Controller
{
    public function __invoke(Request $request, ResourcePackSettings $settings, string $token): Response
    {
        if (strlen($token) !== 64 || !ctype_alnum($token)) {
            abort(404);
        }

        /** @var ?ResourcePack $pack */
        $pack = ResourcePack::query()->with('server.egg')->where('token', $token)->first();

        // Same answer for every refusal, so the endpoint doesn't reveal whether a link exists.
        if (!$pack || !$pack->server || $pack->server->isSuspended() || !$settings->isEnabledForServer($pack->server)) {
            abort(404);
        }

        if ($settings->get('minecraft_clients_only') && !self::isMinecraftClient($request)) {
            abort(404);
        }

        $disk = Storage::disk(ResourcePackService::DISK);
        $path = $pack->getStoragePath();
        if (!$disk->exists($path)) {
            abort(404);
        }

        ResourcePack::query()->whereKey($pack->id)->update([
            'downloads' => $pack->downloads + 1,
            'last_downloaded_at' => now(),
        ]);

        return $disk->download($path, 'pack.zip', [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) $pack->size,
            'ETag' => '"' . $pack->sha1 . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * The Java Edition client sends its own User-Agent and X-Minecraft-* headers when it downloads a
     * server resource pack. Browsers and link-preview bots don't, so they are turned away.
     */
    public static function isMinecraftClient(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();

        return str_starts_with($userAgent, 'Minecraft Java/')
            || ($request->hasHeader('X-Minecraft-Version') && $request->hasHeader('X-Minecraft-UUID'));
    }
}
