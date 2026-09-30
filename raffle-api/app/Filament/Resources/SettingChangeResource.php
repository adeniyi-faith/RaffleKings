<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\SettingChangeResource\Pages;
use App\Filament\Support\MobileCard;
use App\Models\Admin\SettingChange;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use App\Settings\SettingValueFormatter;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * System → Settings history: every change made on the Settings page (what
 * it was, what it became, who changed it, when), with an Undo button. A
 * mistyped price, a wrong payment key or a switch flipped by accident can
 * be put back in one click. Owners only, like Settings itself. The
 * recording and the undo live in App\Settings\SettingsStore. Secret keys
 * are never shown, only that they changed.
 */
class SettingChangeResource extends Resource
{
    use GuardedByStaffRole, RunsAdminActions;

    public static function canViewAny(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $model = SettingChange::class;

    protected static ?string $slug = 'settings-history';

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Settings history';

    protected static ?string $modelLabel = 'setting change';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /** "was → became", in plain words. */
    public static function summary(SettingChange $change): string
    {
        $setting = SettingsRegistry::find($change->key);

        return SettingValueFormatter::format($setting, $change->is_secret ? SettingsStore::historyRead($change, 'old_value') : $change->old_value)
            .' → '
            .SettingValueFormatter::format($setting, $change->is_secret ? SettingsStore::historyRead($change, 'new_value') : $change->new_value);
    }

    /** Where this change stands: in effect, changed again since, or put back. @return array{0: string, 1: string} text and colour */
    public static function status(SettingChange $change): array
    {
        if ($change->reverted_at) {
            return ['Put back by '.($change->reverter?->display_name ?: 'the server').' · '.$change->reverted_at->timezone(config('raffles.timezone'))->format('j M, H:i'), 'gray'];
        }

        return SettingsStore::isCurrent($change) ? ['In effect now', 'success'] : ['Changed again since', 'warning'];
    }

    public static function changedBy(SettingChange $change): string
    {
        return $change->changer?->display_name ?: ($change->changed_by ? "#{$change->changed_by}" : 'The server (command line)');
    }

    private static function sameSave(SettingChange $change)
    {
        return SettingChange::query()->where('batch', $change->batch)->whereNull('reverted_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['changer', 'reverter']))
            ->defaultSort(fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                MobileCard::make(fn (SettingChange $c) => [
                    'title' => $c->label,
                    'lines' => [static::summary($c), static::changedBy($c).' · '.$c->created_at?->timezone(config('raffles.timezone'))->format('j M, H:i')],
                    'badges' => [static::status($c)],
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('j M Y, H:i')->timezone(config('raffles.timezone'))->description(fn (SettingChange $c) => 'by '.static::changedBy($c)),
                    Tables\Columns\TextColumn::make('label')->label('Setting')->weight('bold')->searchable()
                        ->description(function (SettingChange $c) {
                            $others = SettingChange::query()->where('batch', $c->batch)->count() - 1;

                            return $others > 0 ? "Saved together with {$others} other setting".($others === 1 ? '' : 's') : null;
                        }),
                    Tables\Columns\TextColumn::make('summary')->label('Was → became')->state(fn (SettingChange $c) => static::summary($c))->wrap(),
                    Tables\Columns\TextColumn::make('standing')->label('Now')->badge()
                        ->state(fn (SettingChange $c) => static::status($c)[0])->color(fn (SettingChange $c) => static::status($c)[1]),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('label')->label('Setting')->searchable()
                    ->options(fn () => SettingChange::query()->distinct()->orderBy('label')->pluck('label', 'label')->all()),
                Tables\Filters\Filter::make('not_undone')->label('Not put back yet')->toggle()->default()
                    ->query(fn ($query) => $query->whereNull('reverted_at')),
            ])
            ->actions([
                Tables\Actions\Action::make('undo')
                    ->label('Undo')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (SettingChange $c) => $c->reverted_at === null && static::staffCan('settings'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (SettingChange $c) => "Put \"{$c->label}\" back?")
                    ->modalDescription(function (SettingChange $c) {
                        $setting = SettingsRegistry::find($c->key);
                        $back = SettingValueFormatter::format($setting, $c->is_secret ? SettingsStore::historyRead($c, 'old_value') : $c->old_value);
                        $note = SettingsStore::isCurrent($c) ? '' : ' It has been changed again since, so that later change is undone too.';

                        return $c->is_secret
                            ? 'The secret key goes back to what it was before this change (or to the server file\'s key if it had none).'.$note
                            : "It goes back to: {$back}. The site uses it straight away.".$note;
                    })
                    ->action(fn (SettingChange $c) => static::report(SettingsStore::undo([$c], static::admin()))),
                Tables\Actions\Action::make('undoSave')
                    ->label('Undo that whole save')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (SettingChange $c) => $c->reverted_at === null && static::staffCan('settings') && static::sameSave($c)->count() > 1)
                    ->requiresConfirmation()
                    ->modalHeading('Undo everything saved at that moment?')
                    ->modalDescription(fn (SettingChange $c) => 'This puts back all '.static::sameSave($c)->count().' settings that were saved together: '.static::sameSave($c)->pluck('label')->implode(', ').'.')
                    ->action(fn (SettingChange $c) => static::report(SettingsStore::undo(static::sameSave($c)->get(), static::admin()))),
            ])
            ->emptyStateHeading('No settings changed yet')
            ->emptyStateDescription('Every change made on the Settings page shows up here, and can be put back.');
    }

    private static function report(array $result): void
    {
        $restored = count($result['restored']);

        Notification::make()
            ->title($restored > 0 ? 'Put back: '.implode(', ', $result['restored']) : 'Nothing was put back')
            ->body($result['skipped'] ? 'Skipped: '.implode(', ', $result['skipped']) : null)
            ->{$restored > 0 ? 'success' : 'warning'}()->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSettingChanges::route('/'),
        ];
    }
}
