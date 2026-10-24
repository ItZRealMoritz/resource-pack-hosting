<?php

namespace ItZRealMoritz\ResourcePackHosting\Filament;

use App\Models\Egg;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use ItZRealMoritz\ResourcePackHosting\Services\ResourcePackSettings;

/**
 * The settings fields shared by the setup menu, the admin page and the plugin settings dialog.
 */
class SettingsForm
{
    /** @return array<int, \Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    public static function components(): array
    {
        $eggs = Egg::query()->orderBy('name')->get();

        return [
            Section::make(trans('resource-pack-hosting::strings.settings.eggs_section'))
                ->description(trans('resource-pack-hosting::strings.settings.eggs_section_desc'))
                ->icon('tabler-egg')
                ->extraAttributes(['class' => 'rph-section rph-section-1'])
                ->schema([
                    Toggle::make('auto_detect_minecraft')
                        ->label(trans('resource-pack-hosting::strings.settings.auto_detect'))
                        ->helperText(trans('resource-pack-hosting::strings.settings.auto_detect_help'))
                        ->onIcon('tabler-wand')
                        ->live()
                        ->inline(false),
                    CheckboxList::make('egg_ids')
                        ->label(trans('resource-pack-hosting::strings.settings.eggs'))
                        ->helperText(fn (Get $get) => $get('auto_detect_minecraft')
                            ? trans('resource-pack-hosting::strings.settings.eggs_help_auto')
                            : trans('resource-pack-hosting::strings.settings.eggs_help'))
                        ->options($eggs->mapWithKeys(fn (Egg $egg) => [$egg->id => $egg->name])->all())
                        ->descriptions($eggs->mapWithKeys(fn (Egg $egg) => [
                            $egg->id => ResourcePackSettings::isMinecraftEgg($egg)
                                ? new HtmlString('<span style="color: rgb(34 197 94)">● ' . e(trans('resource-pack-hosting::strings.settings.detected_minecraft')) . '</span>')
                                : '',
                        ])->all())
                        ->bulkToggleable()
                        ->searchable($eggs->count() > 8)
                        ->columns(2)
                        ->gridDirection('row'),
                ]),
            Section::make(trans('resource-pack-hosting::strings.settings.limits_section'))
                ->description(trans('resource-pack-hosting::strings.settings.limits_section_desc'))
                ->icon('tabler-database')
                ->extraAttributes(['class' => 'rph-section rph-section-2'])
                ->columns(2)
                ->schema([
                    TextInput::make('quota_gb')
                        ->label(trans('resource-pack-hosting::strings.settings.quota'))
                        ->helperText(trans('resource-pack-hosting::strings.settings.quota_help', ['max' => config('resource-pack-hosting.max_upload_mb')]))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1024)
                        ->step(1)
                        ->suffix('GB')
                        ->prefixIcon('tabler-database')
                        ->required(),
                    Toggle::make('minecraft_clients_only')
                        ->label(trans('resource-pack-hosting::strings.settings.clients_only'))
                        ->helperText(trans('resource-pack-hosting::strings.settings.clients_only_help'))
                        ->onIcon('tabler-shield-lock')
                        ->inline(false),
                ]),
        ];
    }

    /** @return array<string, mixed> */
    public static function fillData(): array
    {
        $settings = app(ResourcePackSettings::class)->all();
        unset($settings['configured']);
        $settings['quota_gb'] = max(1, (int) round($settings['quota_gb']));

        return $settings;
    }

    /** @param  array<string, mixed>  $data */
    public static function save(array $data): void
    {
        app(ResourcePackSettings::class)->save([
            'configured' => true,
            'auto_detect_minecraft' => (bool) ($data['auto_detect_minecraft'] ?? false),
            'egg_ids' => array_values(array_map('intval', (array) ($data['egg_ids'] ?? []))),
            'quota_gb' => max(1, (int) round((float) ($data['quota_gb'] ?? 1))),
            'minecraft_clients_only' => (bool) ($data['minecraft_clients_only'] ?? true),
        ]);
    }
}
