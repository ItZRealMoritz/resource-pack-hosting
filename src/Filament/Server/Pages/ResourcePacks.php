<?php

namespace ItZRealMoritz\ResourcePackHosting\Filament\Server\Pages;

use App\Facades\Activity;
use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Traits\Filament\BlockAccessInConflict;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use ItZRealMoritz\ResourcePackHosting\Exceptions\InvalidPackException;
use ItZRealMoritz\ResourcePackHosting\Jobs\RestartServerJob;
use ItZRealMoritz\ResourcePackHosting\Models\ResourcePack;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackService;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackSettings;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ResourcePacks extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-texture';

    protected static ?string $slug = 'resource-packs';

    protected static ?int $navigationSort = 31;

    public static function canAccess(): bool
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        return parent::canAccess()
            && $server
            && app(ResourcePackSettings::class)->isEnabledForServer($server)
            && user()?->can('resourcepack.read', $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans('resource-pack-hosting::strings.title');
    }

    public function getTitle(): string
    {
        return trans('resource-pack-hosting::strings.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    protected function service(): ResourcePackService
    {
        return app(ResourcePackService::class);
    }

    protected function can(string $permission): bool
    {
        return user()?->can('resourcepack.' . $permission, $this->getServer()) ?? false;
    }

    public function content(Schema $schema): Schema
    {
        $settings = app(ResourcePackSettings::class);

        return $schema
            ->components([
                Grid::make(3)->schema([
                    TextEntry::make('used')
                        ->label(trans('resource-pack-hosting::strings.server.storage_used'))
                        ->state(function () use ($settings) {
                            $used = $this->service()->getUsedBytes($this->getServer());
                            $quota = $settings->getQuotaBytes();
                            $percent = $quota > 0 ? min(100, round($used / $quota * 100)) : 100;

                            return ResourcePackService::formatBytes($used) . ' / ' . ResourcePackService::formatBytes($quota) . " ($percent%)";
                        })
                        ->color(function () use ($settings) {
                            $quota = $settings->getQuotaBytes();
                            $ratio = $quota > 0 ? $this->service()->getUsedBytes($this->getServer()) / $quota : 1;

                            return match (true) {
                                $ratio >= 0.9 => 'danger',
                                $ratio >= 0.7 => 'warning',
                                default => 'success',
                            };
                        })
                        ->icon('tabler-database')
                        ->badge(),
                    TextEntry::make('count')
                        ->label(trans('resource-pack-hosting::strings.server.packs'))
                        ->state(fn () => ResourcePack::query()->where('server_id', $this->getServer()->id)->count())
                        ->icon('tabler-package')
                        ->badge(),
                    TextEntry::make('protection')
                        ->label(trans('resource-pack-hosting::strings.server.protection'))
                        ->state(fn () => $settings->get('minecraft_clients_only')
                            ? trans('resource-pack-hosting::strings.server.protection_on')
                            : trans('resource-pack-hosting::strings.server.protection_off'))
                        ->color(fn () => $settings->get('minecraft_clients_only') ? 'success' : 'gray')
                        ->icon('tabler-shield-lock')
                        ->badge(),
                ]),
                EmbeddedTable::make(),
            ]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->uploadAction(),
        ];
    }

    protected function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(trans('resource-pack-hosting::strings.server.upload'))
            ->icon('tabler-upload')
            ->visible(fn () => $this->can('create'))
            ->modalHeading(trans('resource-pack-hosting::strings.server.upload_heading'))
            ->modalDescription(trans('resource-pack-hosting::strings.server.upload_description'))
            ->modalIcon('tabler-file-zip')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(trans('resource-pack-hosting::strings.server.upload_submit'))
            ->schema([
                TextInput::make('name')
                    ->label(trans('resource-pack-hosting::strings.server.name'))
                    ->placeholder(trans('resource-pack-hosting::strings.server.name_placeholder'))
                    ->maxLength(100),
                FileUpload::make('pack')
                    ->label(trans('resource-pack-hosting::strings.server.file'))
                    ->helperText(fn () => trans('resource-pack-hosting::strings.server.file_help', [
                        'remaining' => ResourcePackService::formatBytes($this->service()->getRemainingBytes($this->getServer())),
                    ]))
                    ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip'])
                    ->maxSize(fn () => max(1, (int) floor(min(
                        (int) config('resource-pack-hosting.max_upload_mb', 100) * 1024 * 1024,
                        $this->service()->getRemainingBytes($this->getServer()),
                    ) / 1024)))
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data, Action $action) {
                abort_unless($this->can('create'), 403);

                $file = is_array($data['pack']) ? reset($data['pack']) : $data['pack'];
                if (!$file instanceof TemporaryUploadedFile) {
                    $action->halt();
                }

                $server = $this->getServer();

                try {
                    $pack = $this->service()->store($server, $file->getRealPath(), $file->getClientOriginalName(), $data['name'] ?? null);
                } catch (InvalidPackException $exception) {
                    Notification::make()
                        ->title(trans('resource-pack-hosting::strings.notifications.upload_rejected'))
                        ->body($exception->getMessage())
                        ->icon('tabler-shield-x')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                } catch (Exception $exception) {
                    report($exception);

                    Notification::make()
                        ->title(trans('resource-pack-hosting::strings.notifications.upload_failed'))
                        ->danger()
                        ->send();

                    return;
                } finally {
                    $file->delete();
                }

                Activity::event('server:resourcepack.upload')
                    ->property(['name' => $pack->name, 'sha1' => $pack->sha1, 'size' => $pack->size])
                    ->log();

                // Swap the upload form for a small follow-up dialog about applying the pack.
                $this->replaceMountedAction('uploaded', ['pack' => $pack->id]);
            });
    }

    public function uploadedAction(): Action
    {
        return Action::make('uploaded')
            ->modalHeading(trans('resource-pack-hosting::strings.uploaded.heading'))
            ->modalDescription(trans('resource-pack-hosting::strings.uploaded.description'))
            ->modalIcon('tabler-circle-check')
            ->modalIconColor('success')
            ->modalWidth(Width::Medium)
            ->modalSubmitActionLabel(trans('resource-pack-hosting::strings.uploaded.done'))
            ->modalCancelAction(false)
            ->schema(fn () => [
                Checkbox::make('apply')
                    ->label(trans('resource-pack-hosting::strings.uploaded.apply'))
                    ->helperText(trans('resource-pack-hosting::strings.uploaded.apply_help'))
                    ->visible(fn () => $this->can('update'))
                    ->default(true)
                    ->live(),
                Checkbox::make('required')
                    ->label(trans('resource-pack-hosting::strings.server.required'))
                    ->helperText(trans('resource-pack-hosting::strings.server.required_help'))
                    ->visible(fn (Get $get) => $this->can('update') && $get('apply'))
                    ->default(false),
                ToggleButtons::make('restart')
                    ->label(trans('resource-pack-hosting::strings.uploaded.restart'))
                    ->visible(fn () => $this->canRestart())
                    ->options([
                        'none' => trans('resource-pack-hosting::strings.uploaded.restart_none'),
                        'now' => trans('resource-pack-hosting::strings.uploaded.restart_now'),
                        'timer' => trans('resource-pack-hosting::strings.uploaded.restart_timer'),
                        'time' => trans('resource-pack-hosting::strings.uploaded.restart_time'),
                    ])
                    ->icons([
                        'none' => 'tabler-player-pause',
                        'now' => 'tabler-reload',
                        'timer' => 'tabler-hourglass',
                        'time' => 'tabler-clock',
                    ])
                    ->default('none')
                    ->inline()
                    ->live(),
                TextInput::make('restart_minutes')
                    ->label(trans('resource-pack-hosting::strings.uploaded.restart_in'))
                    ->visible(fn (Get $get) => $this->canRestart() && $get('restart') === 'timer')
                    ->required(fn (Get $get) => $get('restart') === 'timer')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1440)
                    ->default(5)
                    ->suffix(trans('resource-pack-hosting::strings.uploaded.minutes'))
                    ->prefixIcon('tabler-hourglass'),
                TimePicker::make('restart_at')
                    ->label(trans('resource-pack-hosting::strings.uploaded.restart_at'))
                    ->visible(fn (Get $get) => $this->canRestart() && $get('restart') === 'time')
                    ->required(fn (Get $get) => $get('restart') === 'time')
                    ->seconds(false)
                    ->timezone(fn () => $this->getTimezone())
                    ->prefixIcon('tabler-clock')
                    ->helperText(fn () => trans('resource-pack-hosting::strings.uploaded.timezone_hint', ['timezone' => $this->getTimezone()])),
            ])
            ->action(function (array $data, array $arguments) {
                if (($data['apply'] ?? false) && $this->can('update')) {
                    /** @var ResourcePack $pack */
                    $pack = ResourcePack::query()
                        ->with('server')
                        ->where('server_id', $this->getServer()->id)
                        ->findOrFail((int) ($arguments['pack'] ?? 0));

                    $this->applyPack($pack, (bool) ($data['required'] ?? false));
                }

                if ($this->canRestart()) {
                    $this->scheduleRestart($data['restart'] ?? 'none', $data);
                }
            });
    }

    protected function canRestart(): bool
    {
        return user()?->can(SubuserPermission::ControlRestart, $this->getServer()) ?? false;
    }

    protected function getTimezone(): string
    {
        return user()->timezone ?? config('app.timezone', 'UTC');
    }

    /** @param  array<string, mixed>  $data */
    protected function scheduleRestart(string $mode, array $data): void
    {
        $server = $this->getServer();
        $timezone = $this->getTimezone();

        $runAt = match ($mode) {
            'timer' => now()->addMinutes(max(1, min(1440, (int) ($data['restart_minutes'] ?? 5)))),
            'time' => (function () use ($data) {
                // The picker shows the user's time zone and hands back the time in the app time zone.
                $time = Carbon::parse((string) $data['restart_at'], date_default_timezone_get());
                // A time that already passed today means the same time tomorrow.
                if ($time->lessThanOrEqualTo(now())) {
                    $time->addDay();
                }

                return $time;
            })(),
            default => null,
        };

        try {
            if ($mode === 'now') {
                app(DaemonServerRepository::class)->setServer($server)->power('restart');

                Notification::make()
                    ->title(trans('resource-pack-hosting::strings.notifications.restarting'))
                    ->success()
                    ->send();

                return;
            }

            if (!$runAt) {
                return;
            }

            RestartServerJob::dispatch($server)->delay($runAt);
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title(trans('resource-pack-hosting::strings.notifications.restart_failed'))
                ->danger()
                ->send();

            return;
        }

        Activity::event('server:resourcepack.restart-scheduled')
            ->property(['at' => $runAt->toIso8601String()])
            ->log();

        Notification::make()
            ->title(trans('resource-pack-hosting::strings.notifications.restart_scheduled'))
            ->body(trans('resource-pack-hosting::strings.notifications.restart_scheduled_body', [
                'time' => $runAt->clone()->timezone($timezone)->format('Y-m-d H:i'),
                'timezone' => $timezone,
            ]))
            ->icon('tabler-clock')
            ->success()
            ->send();
    }

    protected function applyPack(ResourcePack $pack, bool $required): void
    {
        abort_unless($this->can('update'), 403);

        try {
            $this->service()->applyToServerProperties($pack, $required);
        } catch (Exception $exception) {
            report($exception);

            Notification::make()
                ->title(trans('resource-pack-hosting::strings.notifications.apply_failed'))
                ->body(trans('resource-pack-hosting::strings.notifications.apply_failed_body'))
                ->danger()
                ->send();

            return;
        }

        Activity::event('server:resourcepack.apply')
            ->property(['name' => $pack->name])
            ->log();

        Notification::make()
            ->title(trans('resource-pack-hosting::strings.notifications.applied'))
            ->body(trans('resource-pack-hosting::strings.notifications.applied_body'))
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ResourcePack::query()->with('server')->where('server_id', $this->getServer()->id))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('tabler-texture')
            ->emptyStateHeading(trans('resource-pack-hosting::strings.server.empty'))
            ->emptyStateDescription(trans('resource-pack-hosting::strings.server.empty_desc'))
            ->columns([
                TextColumn::make('name')
                    ->label(trans('resource-pack-hosting::strings.server.name'))
                    ->icon('tabler-package')
                    ->description(fn (ResourcePack $pack) => $pack->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pack_format')
                    ->label(trans('resource-pack-hosting::strings.server.pack_format'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('size')
                    ->label(trans('resource-pack-hosting::strings.server.size'))
                    ->formatStateUsing(fn (int $state) => ResourcePackService::formatBytes($state))
                    ->sortable(),
                TextColumn::make('sha1')
                    ->label('SHA-1')
                    ->fontFamily(FontFamily::Mono)
                    ->limit(12)
                    ->tooltip(fn (ResourcePack $pack) => $pack->sha1)
                    ->copyable()
                    ->copyableState(fn (ResourcePack $pack) => $pack->sha1)
                    ->copyMessage(trans('resource-pack-hosting::strings.server.copied')),
                TextColumn::make('link')
                    ->label(trans('resource-pack-hosting::strings.server.link'))
                    ->state(fn (ResourcePack $pack) => $pack->getDownloadUrl())
                    ->icon('tabler-link')
                    ->limit(28)
                    ->copyable()
                    ->copyMessage(trans('resource-pack-hosting::strings.server.copied')),
                TextColumn::make('downloads')
                    ->label(trans('resource-pack-hosting::strings.server.downloads'))
                    ->icon('tabler-download')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(trans('resource-pack-hosting::strings.server.uploaded'))
                    ->formatStateUsing(fn (Carbon $state) => $state->diffForHumans())
                    ->tooltip(fn (ResourcePack $pack) => $pack->created_at->timezone(user()->timezone ?? 'UTC')->format('Y-m-d H:i'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('properties')
                    ->label(trans('resource-pack-hosting::strings.server.use'))
                    ->icon('tabler-file-settings')
                    ->color('primary')
                    ->modalHeading(fn (ResourcePack $pack) => trans('resource-pack-hosting::strings.server.use_heading', ['name' => $pack->name]))
                    ->modalDescription(trans('resource-pack-hosting::strings.server.use_description'))
                    ->modalIcon('tabler-file-settings')
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalSubmitActionLabel(trans('resource-pack-hosting::strings.server.apply'))
                    ->modalSubmitAction(fn (Action $action) => $this->can('update') ? $action : false)
                    ->schema(fn (ResourcePack $pack) => [
                        Section::make()->schema([
                            TextEntry::make('resource_pack')
                                ->label('resource-pack')
                                ->state($pack->getDownloadUrl())
                                ->fontFamily(FontFamily::Mono)
                                ->copyable(),
                            TextEntry::make('resource_pack_sha1')
                                ->label('resource-pack-sha1')
                                ->state($pack->sha1)
                                ->fontFamily(FontFamily::Mono)
                                ->copyable(),
                        ]),
                        Toggle::make('required')
                            ->label(trans('resource-pack-hosting::strings.server.required'))
                            ->helperText(trans('resource-pack-hosting::strings.server.required_help'))
                            ->visible(fn () => $this->can('update'))
                            ->default(false),
                    ])
                    ->action(fn (array $data, ResourcePack $pack) => $this->applyPack($pack, (bool) ($data['required'] ?? false))),
                Action::make('regenerate')
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('resource-pack-hosting::strings.server.regenerate'))
                    ->visible(fn () => $this->can('update'))
                    ->requiresConfirmation()
                    ->modalHeading(trans('resource-pack-hosting::strings.server.regenerate'))
                    ->modalDescription(trans('resource-pack-hosting::strings.server.regenerate_desc'))
                    ->action(function (ResourcePack $pack) {
                        abort_unless($this->can('update'), 403);

                        $this->service()->regenerateLink($pack);

                        Activity::event('server:resourcepack.regenerate')
                            ->property(['name' => $pack->name])
                            ->log();

                        Notification::make()
                            ->title(trans('resource-pack-hosting::strings.notifications.regenerated'))
                            ->success()
                            ->send();
                    }),
                Action::make('delete')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('resource-pack-hosting::strings.server.delete'))
                    ->visible(fn () => $this->can('delete'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (ResourcePack $pack) => trans('resource-pack-hosting::strings.server.delete_heading', ['name' => $pack->name]))
                    ->modalDescription(trans('resource-pack-hosting::strings.server.delete_desc'))
                    ->action(function (ResourcePack $pack) {
                        abort_unless($this->can('delete'), 403);

                        $this->service()->delete($pack);

                        Activity::event('server:resourcepack.delete')
                            ->property(['name' => $pack->name])
                            ->log();

                        Notification::make()
                            ->title(trans('resource-pack-hosting::strings.notifications.deleted'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
