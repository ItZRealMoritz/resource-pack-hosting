<?php

namespace ItZRealMoritz\ResourcePackHosting;

use App\Contracts\Plugins\HasPluginSettings;
use Filament\Contracts\Plugin;
use Filament\Notifications\Notification;
use Filament\Panel;
use ItZRealMoritz\ResourcePackHosting\Filament\SettingsForm;

class ResourcePackHostingPlugin implements HasPluginSettings, Plugin
{
    public function getId(): string
    {
        return 'resource-pack-hosting';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "ItZRealMoritz\\ResourcePackHosting\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return SettingsForm::fillData();
    }

    public function getSettingsForm(): array
    {
        return SettingsForm::components();
    }

    public function saveSettings(array $data): void
    {
        SettingsForm::save($data);

        Notification::make()
            ->title(trans('resource-pack-hosting::strings.notifications.settings_saved'))
            ->success()
            ->send();
    }
}
