<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\BroadcastResource;
use App\Models\Broadcast;
use App\Services\Messaging\BroadcastService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;

class ViewBroadcast extends ViewRecord
{
    use RunsAdminActions;

    protected static string $resource = BroadcastResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }

    /** Sending moves on in the background: the page keeps its numbers fresh. */
    protected function getPollingInterval(): ?string
    {
        return in_array($this->getRecord()->status, ['scheduled', 'sending'], true) ? '10s' : null;
    }

    protected function getHeaderActions(): array
    {
        $messages = fn () => app(BroadcastService::class);
        $refresh = fn () => $this->record->refresh();

        return [
            Action::make('sendNow')
                ->label(fn (Broadcast $record) => $record->status === 'failed' ? 'Carry on sending' : 'Send now')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (Broadcast $record) => in_array($record->status, ['scheduled', 'failed'], true))
                ->requiresConfirmation()
                ->modalDescription(fn (Broadcast $record) => $record->status === 'failed'
                    ? 'It picks up where it stopped. Customers who already got it are never sent it again.'
                    : 'It starts going out straight away instead of waiting for the scheduled time.')
                ->action(function (Broadcast $record) use ($messages, $refresh) {
                    $messages()->sendNow($record, static::admin());
                    $refresh();
                    Notification::make()->title('Sending')->success()->send();
                }),
            Action::make('reschedule')
                ->label('Change the time')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible(fn (Broadcast $record) => $record->status === 'scheduled')
                ->fillForm(fn (Broadcast $record) => ['scheduled_at' => $record->scheduled_at])
                ->form([
                    Forms\Components\DateTimePicker::make('scheduled_at')->label('Send at')->seconds(false)->required()
                        ->timezone(config('raffles.timezone'))->minDate(now())
                        ->helperText('In '.config('raffles.timezone').' time.'),
                ])
                ->action(function (Broadcast $record, array $data) use ($messages, $refresh) {
                    if (! $messages()->reschedule($record, Carbon::parse($data['scheduled_at']), static::admin())) {
                        Notification::make()->title('Not changed')->body('Pick a time in the future. A message that has already started can\'t be moved.')->danger()->send();

                        return;
                    }
                    $refresh();
                    Notification::make()->title('Time changed')->success()->send();
                }),
            Action::make('cancel')
                ->label(fn (Broadcast $record) => $record->status === 'scheduled' ? 'Cancel this message' : 'Stop sending')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Broadcast $record) => in_array($record->status, ['scheduled', 'sending', 'failed'], true))
                ->requiresConfirmation()
                ->modalDescription(fn (Broadcast $record) => $record->status === 'scheduled'
                    ? 'Nobody will get it.'
                    : 'Customers who already got it keep it. The rest will not get it.')
                ->action(function (Broadcast $record) use ($messages, $refresh) {
                    $messages()->cancel($record, static::admin());
                    $refresh();
                    Notification::make()->title('Cancelled')->success()->send();
                }),
        ];
    }
}
