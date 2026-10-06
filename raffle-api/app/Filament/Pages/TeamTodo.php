<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Support\MobileCard;
use App\Models\Admin\StaffTask;
use App\Models\Legacy\WpUser;
use App\Services\Admin\StaffTodo;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Team to-do: everything waiting for staff, as one shared checklist (the
 * admin bell shows the first few). Anyone can tick a to-do off; everyone
 * then sees who did it and when. To-dos close by themselves once the thing
 * is sorted in its own queue. See App\Services\Admin\StaffTodo.
 */
class TeamTodo extends Page implements HasTable
{
    use InteractsWithTable, RunsAdminActions;

    /** Every staff member: each sees the to-dos their role's screens allow. */
    public static function canAccess(): bool
    {
        $user = auth('wordpress')->user();

        return $user instanceof WpUser && $user->staffRole() !== null;
    }

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Team to-do';

    protected static ?string $title = 'Team to-do';

    protected static ?string $slug = 'team-to-do';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.team-todo';

    /** "open" | "done" | "all" */
    public string $show = 'open';

    public function mount(): void
    {
        app(StaffTodo::class)->syncIfDue();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(StaffTodo::class)->openCount(auth('wordpress')->user());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getSubheading(): ?string
    {
        return 'Everything waiting for the team. Tick a to-do off when it\'s handled, and everyone sees who did it. To-dos also close by themselves once sorted in their own screen.';
    }

    public function updatedShow(): void
    {
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Add a to-do')
                ->icon('heroicon-o-plus')
                ->form([
                    Forms\Components\TextInput::make('title')->label('What needs doing?')->required()->maxLength(190),
                    Forms\Components\Textarea::make('detail')->label('More detail (optional)')->rows(2)->maxLength(290),
                ])
                ->action(function (array $data) {
                    app(StaffTodo::class)->add($data['title'], $data['detail'] ?? null, static::admin());
                    Notification::make()->title('Added to the team to-do list')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $todo = app(StaffTodo::class);

        return $table
            ->query(fn () => $todo->query(auth('wordpress')->user())
                ->when($this->show === 'open', fn (Builder $q) => $q->open())
                ->when($this->show === 'done', fn (Builder $q) => $q->done()))
            ->modifyQueryUsing(fn (Builder $query) => $this->show === 'open'
                ? $query->orderByRaw('COALESCE(waiting_since, created_at)')->orderBy('id')
                : $query->orderByRaw('done_at IS NULL DESC')->orderByDesc('done_at')->orderByDesc('id'))
            ->recordUrl(fn (StaffTask $t) => StaffTodo::url($t))
            ->columns([
                MobileCard::make(fn (StaffTask $t) => [
                    'title' => $t->title,
                    'lines' => [$t->detail, $t->isDone() ? $this->doneLine($t) : null],
                    'badges' => [[StaffTodo::label($t), 'gray'], $t->isDone() ? ['Done', 'success'] : null],
                    'meta' => 'Waiting since '.($t->waiting_since ?? $t->created_at)->diffForHumans(),
                ]),
                ...MobileCard::desktop([
                    Tables\Columns\IconColumn::make('done_at')->label('')
                        ->state(fn (StaffTask $t) => $t->isDone())
                        ->icon(fn (bool $state) => $state ? 'heroicon-s-check-circle' : 'heroicon-o-stop')
                        ->color(fn (bool $state) => $state ? 'success' : 'gray')
                        ->tooltip(fn (StaffTask $record) => $record->isDone() ? null : 'Mark done')
                        // Tapping the empty box ticks it off straight away (no note).
                        ->action(fn (StaffTask $record) => $record->isDone() ? null : $this->tick($record, null)),
                    Tables\Columns\TextColumn::make('title')->label('To-do')->wrap()
                        ->description(fn (StaffTask $t) => $t->detail)
                        ->color(fn (StaffTask $t) => $t->isDone() ? 'gray' : null),
                    Tables\Columns\TextColumn::make('source')->label('From')->badge()->color('gray')
                        ->icon(fn (StaffTask $t) => StaffTodo::icon($t))
                        ->formatStateUsing(fn ($state, StaffTask $t) => StaffTodo::label($t)),
                    Tables\Columns\TextColumn::make('waiting_since')->label('Waiting since')
                        ->state(fn (StaffTask $t) => $t->waiting_since ?? $t->created_at)->since(),
                    Tables\Columns\TextColumn::make('done_by_name')->label('Done by')
                        ->state(fn (StaffTask $t) => $t->isDone() ? $this->doneLine($t) : null)
                        ->description(fn (StaffTask $t) => $t->done_note)
                        ->placeholder('Not yet')->wrap(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->label('From')
                    // By label, so the three kinds of fraud-watch flag are one choice.
                    ->options(fn () => collect($todo->visibleSources(auth('wordpress')->user()))
                        ->map(fn ($s) => StaffTodo::SOURCES[$s]['label'])->unique()->mapWithKeys(fn ($l) => [$l => $l])->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('source', array_keys(array_filter(StaffTodo::SOURCES, fn ($s) => $s['label'] === $data['value'])))
                        : $query),
            ])
            ->actions([
                Tables\Actions\Action::make('done')
                    ->label('Mark done')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (StaffTask $t) => ! $t->isDone())
                    ->modalHeading(fn (StaffTask $t) => 'Mark done: '.$t->title)
                    ->modalDescription('Everyone on the team will see that you handled this.')
                    ->form([
                        Forms\Components\TextInput::make('note')->label('Note for the team (optional)')
                            ->placeholder('e.g. Paid from the bank app')->maxLength(290),
                    ])
                    ->modalSubmitActionLabel('Mark done')
                    ->action(fn (StaffTask $t, array $data) => $this->tick($t, $data['note'] ?? null)),
                Tables\Actions\Action::make('reopen')
                    ->label('Not done')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (StaffTask $t) => $t->isDone() && $t->cleared_at === null)
                    ->action(function (StaffTask $t) {
                        abort_unless(app(StaffTodo::class)->canTouch(auth('wordpress')->user(), $t), 403);
                        app(StaffTodo::class)->reopen($t);
                        Notification::make()->title('Back on the list')->success()->send();
                    }),
            ])
            ->emptyStateIcon('heroicon-o-check-badge')
            ->emptyStateHeading(fn () => $this->show === 'done' ? 'Nothing ticked off yet' : 'All clear')
            ->emptyStateDescription(fn () => $this->show === 'open' ? 'Nothing is waiting for the team right now.' : null)
            ->paginated([25, 50, 100]);
    }

    private function tick(StaffTask $task, ?string $note): void
    {
        abort_unless(app(StaffTodo::class)->canTouch(auth('wordpress')->user(), $task), 403);
        app(StaffTodo::class)->markDone($task, static::admin(), $note);
        Notification::make()->title('Marked done')->success()->send();
    }

    private function doneLine(StaffTask $t): string
    {
        return ($t->done_by ? 'Done by '.$t->doneByLabel() : $t->doneByLabel()).', '.$t->done_at->diffForHumans();
    }
}
