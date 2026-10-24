<?php

namespace ItZRealMoritz\ResourcePackHosting\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use ItZRealMoritz\ResourcePackHosting\Http\Controllers\ResourcePackDownloadController;

class ResourcePackHostingRoutesProvider extends RouteServiceProvider
{
    public const DOWNLOAD_THROTTLE_KEY = 'resource-pack-hosting.download';

    public function boot(): void
    {
        RateLimiter::for(self::DOWNLOAD_THROTTLE_KEY, function (Request $request) {
            return Limit::perMinute(max(1, (int) config('resource-pack-hosting.downloads_per_minute', 30)))->by($request->ip());
        });

        // Deliberately outside the "web" group: the Minecraft client has no session, cookies or CSRF token.
        $this->routes(function () {
            Route::get('/resourcepacks/{token}/pack.zip', ResourcePackDownloadController::class)
                ->where('token', '[A-Za-z0-9]{64}')
                ->middleware('throttle:' . self::DOWNLOAD_THROTTLE_KEY)
                ->name('resource-pack-hosting.download');
        });
    }
}
