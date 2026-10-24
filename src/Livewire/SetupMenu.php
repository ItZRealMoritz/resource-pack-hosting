<?php

namespace ItZRealMoritz\ResourcePackHosting\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use ItZRealMoritz\ResourcePackHosting\Filament\SettingsForm;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackSettings;
use Livewire\Component;

class SetupMenu extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public const SNOOZE_KEY = 'resource-pack-hosting.setup_snoozed';

    public static function shouldRender(): bool
    {
        if (!user()?->isRootAdmin() || session(self::SNOOZE_KEY)) {
            return false;
        }

        return !app(ResourcePackSettings::class)->isConfigured();
    }

    public function openSetup(): void
    {
        if (static::shouldRender()) {
            $this->mountAction('setup');
        }
    }

    public function setupAction(): Action
    {
        return Action::make('setup')
            ->modalHeading(trans('resource-pack-hosting::strings.setup.heading'))
            ->modalDescription(trans('resource-pack-hosting::strings.setup.description'))
            ->modalIcon('tabler-texture')
            ->modalIconColor('primary')
            ->modalWidth(Width::ThreeExtraLarge)
            ->extraModalWindowAttributes(['class' => 'rph-setup-window'])
            ->closeModalByClickingAway(false)
            ->modalSubmitActionLabel(trans('resource-pack-hosting::strings.setup.save'))
            ->modalCancelAction(false)
            ->extraModalFooterActions([
                Action::make('later')
                    ->label(trans('resource-pack-hosting::strings.setup.later'))
                    ->color('gray')
                    ->action(fn () => session()->put(self::SNOOZE_KEY, true))
                    ->cancelParentActions(),
            ])
            ->fillForm(fn () => SettingsForm::fillData())
            ->schema(fn () => SettingsForm::components())
            ->action(function (array $data) {
                abort_unless(user()?->isRootAdmin(), 403);

                SettingsForm::save($data);

                Notification::make()
                    ->title(trans('resource-pack-hosting::strings.notifications.settings_saved'))
                    ->body(trans('resource-pack-hosting::strings.notifications.settings_saved_body'))
                    ->icon('tabler-texture')
                    ->success()
                    ->send();
            });
    }

    public function render(): View
    {
        return view('resource-pack-hosting::livewire.setup-menu');
    }
}
