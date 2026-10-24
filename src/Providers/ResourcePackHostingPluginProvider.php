<?php

namespace ItZRealMoritz\ResourcePackHosting\Providers;

use App\Models\Server;
use App\Models\Subuser;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use ItZRealMoritz\ResourcePackHosting\Livewire\SetupMenu;
use ItZRealMoritz\ResourcePackHosting\Services\PackValidator;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackService;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackSettings;
use Throwable;

class ResourcePackHostingPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ResourcePackSettings::class);
        $this->app->singleton(PackValidator::class);
        $this->app->singleton(ResourcePackService::class);

        Subuser::registerCustomPermissions('resourcepack', ['read', 'create', 'update', 'delete'], 'resource-pack-hosting::permissions', 'tabler-photo-scan', false);
    }

    public function boot(): void
    {
        // Pops up the animated setup menu for root admins until the plugin has been configured.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function () {
                // A broken setup menu must never take the whole panel down with it.
                try {
                    return SetupMenu::shouldRender() ? Blade::render('@livewire(\\' . SetupMenu::class . '::class)') : '';
                } catch (Throwable $exception) {
                    report($exception);

                    return '';
                }
            },
        );

        Server::deleting(function (Server $server) {
            app(ResourcePackService::class)->deleteAllForServer($server);
        });
    }
}
