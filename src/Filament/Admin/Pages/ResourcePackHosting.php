<?php

namespace ItZRealMoritz\ResourcePackHosting\Filament\Admin\Pages;

use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use ItZRealMoritz\ResourcePackHosting\Filament\SettingsForm;
use ItZRealMoritz\ResourcePackHosting\Models\ResourcePack;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackService;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackSettings;

/**
 * @property Schema $form
 */
class ResourcePackHosting extends Page
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-texture';

    protected static ?string $slug = 'resource-packs';

    protected static ?int $navigationSort = 99;

    protected string $view = 'resource-pack-hosting::admin.settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return user()?->isRootAdmin() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return trans('resource-pack-hosting::strings.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.advanced');
    }

    public function getTitle(): string
    {
        return trans('resource-pack-hosting::strings.title');
    }

    public function mount(): void
    {
        $this->form->fill(SettingsForm::fillData());
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(ResourcePackSettings::class);

        return $schema
            ->statePath('data')
            ->components([
                Grid::make(3)->schema([
                    TextEntry::make('stat_packs')
                        ->label(trans('resource-pack-hosting::strings.admin.total_packs'))
                        ->state(fn () => ResourcePack::query()->count())
                        ->icon('tabler-package')
                        ->badge(),
                    TextEntry::make('stat_storage')
                        ->label(trans('resource-pack-hosting::strings.admin.total_storage'))
                        ->state(fn () => ResourcePackService::formatBytes((int) ResourcePack::query()->sum('size')))
                        ->icon('tabler-database')
                        ->badge(),
                    TextEntry::make('stat_servers')
                        ->label(trans('resource-pack-hosting::strings.admin.enabled_servers'))
                        ->state(fn () => Server::query()->with('egg')->get()->filter(fn (Server $server) => $settings->isEnabledForServer($server))->count())
                        ->icon('tabler-server')
                        ->badge(),
                ]),
                ...SettingsForm::components(),
            ]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(trans('resource-pack-hosting::strings.setup.save'))
                ->icon('tabler-device-floppy')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        abort_unless(user()?->isRootAdmin(), 403);

        SettingsForm::save($this->form->getState());

        Notification::make()
            ->title(trans('resource-pack-hosting::strings.notifications.settings_saved'))
            ->success()
            ->send();
    }
}
