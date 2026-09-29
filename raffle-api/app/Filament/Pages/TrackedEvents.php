<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardedByStaffRole;
use App\Filament\Concerns\RunsAdminActions;
use App\Services\Analytics\EventCatalog;
use App\Settings\SettingsStore;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * System → Tracked Events: one switch per thing the site records for
 * analytics. Switching one off stops it being sent from the very next page
 * load or action, in the browser and on the server. The list itself lives in
 * App\Services\Analytics\EventCatalog.
 */
class TrackedEvents extends Page implements HasForms
{
    use GuardedByStaffRole, InteractsWithForms, RunsAdminActions;

    public static function canAccess(): bool
    {
        return static::staffCanOpen();
    }

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Tracked Events';

    protected static ?string $title = 'Tracked Events';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'tracked-events';

    protected static string $view = 'filament.pages.tracked-events';

    /** @var array<string, mixed> */
    public array $data = [];

    public function getSubheading(): ?string
    {
        return 'Choose what the site records for analytics. On = recorded. Changes apply straight away. This only matters once PostHog or Google Analytics is set up under Settings.';
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    /** Dots and $ don't survive in form state, so a key becomes e__<key>. */
    public static function field(string $event): string
    {
        return 'e__'.str_replace('$', '_dollar_', $event);
    }

    private function fillForm(): void
    {
        $state = [];

        foreach (EventCatalog::all() as $event => $meta) {
            $state[self::field($event)] = ! EventCatalog::isDisabled($event);
        }

        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        $sections = [];

        foreach (EventCatalog::groups() as $group => $events) {
            $fields = [];

            foreach ($events as $event => $meta) {
                $where = $meta['source'] === EventCatalog::SERVER ? 'Sent from our server' : 'Sent from the visitor\'s browser';
                $fields[] = Forms\Components\Toggle::make(self::field($event))
                    ->label($meta['label'])
                    ->helperText(trim($meta['help'].' ('.$where.'.)'))
                    ->onColor('success')
                    ->offColor('gray');
            }

            $sections[] = Forms\Components\Section::make($group)->schema($fields)->columns(2)->collapsible();
        }

        return $form->schema($sections)->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $off = [];

        foreach (array_keys(EventCatalog::all()) as $event) {
            if (empty($state[self::field($event)])) {
                $off[] = $event;
            }
        }

        $changed = SettingsStore::save(['services.analytics.disabled_events' => $off], static::admin());
        $this->fillForm();

        Notification::make()
            ->title($changed === [] ? 'Nothing changed' : 'Saved and live on the site now')
            ->body($changed === [] ? null : (count($off) === 0 ? 'Everything is being recorded.' : count($off).' event(s) switched off.'))
            ->{$changed === [] ? 'info' : 'success'}()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save changes')->submit('save')->keyBindings(['mod+s']),
        ];
    }

    public function areFormActionsSticky(): bool
    {
        return true;
    }
}
