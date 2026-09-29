<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Filament\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;

/** One ticket's full conversation, with Reply and Change status. */
class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function getTitle(): string
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->getRecord();

        return "#{$ticket->id} · {$ticket->subject}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reply')
                ->label('Reply')
                ->icon('heroicon-o-paper-airplane')
                ->form(SupportTicketResource::replyForm($this->getRecord()))
                ->action(function (array $data) {
                    $this->run(
                        fn () => SupportTicketResource::reply($this->getRecord(), $data['message'], (bool) ($data['resolve'] ?? false)),
                        'Reply sent to the customer.',
                    );
                }),
            Actions\Action::make('changeStatus')
                ->label('Change status')
                ->icon('heroicon-o-flag')
                ->color('gray')
                ->fillForm(fn () => ['status' => $this->getRecord()->status])
                ->form([
                    Forms\Components\Select::make('status')
                        ->options(SupportTicketResource::STATUS_LABELS)
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->run(
                        fn () => SupportTicketResource::changeStatus($this->getRecord(), $data['status']),
                        'Status updated.',
                    );
                }),
        ];
    }

    private function run(callable $action, string $success): void
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            Notification::make()->title('Not done')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->getRecord()->refresh();
        Notification::make()->title($success)->success()->send();
    }
}
