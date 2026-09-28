<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\BroadcastResource;
use App\Models\Legacy\RaffleNotificationTemplate;
use App\Services\Messaging\Audience;
use App\Services\Messaging\BroadcastService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

class CreateBroadcast extends CreateRecord
{
    use RunsAdminActions;

    protected static string $resource = BroadcastResource::class;

    protected static ?string $title = 'New message';

    protected static bool $canCreateAnother = false;

    /** Form state → what BroadcastService needs. */
    private function payload(array $state): array
    {
        return [
            'title' => $state['title'],
            'body' => $state['body'],
            'link_url' => $state['link_url'] ?? null,
            'link_label' => $state['link_label'] ?? null,
            'channels' => array_values($state['channels'] ?? []),
            'audience' => $state['audience'],
            'audience_options' => array_filter([
                'raffle_id' => $state['raffle_id'] ?? null,
                'days' => $state['days'] ?? null,
                'min_amount' => $state['min_amount'] ?? null,
                'user_id' => $state['user_id'] ?? null,
            ], fn ($v) => filled($v)),
        ];
    }

    private function recipients(): int
    {
        $p = $this->payload($this->data + ['title' => '', 'body' => '']);

        return app(Audience::class)->count((string) $p['audience'], $p['audience_options']);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Send')
            ->icon('heroicon-o-paper-airplane')
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading(fn () => 'Send to '.number_format($this->recipients()).' customers?')
            ->modalDescription('This can\'t be undone. Emails and notifications go out over the next few minutes.')
            ->modalSubmitActionLabel('Yes, send it')
            ->action('create');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send me a test')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->action(function () {
                    $state = $this->form->getState();

                    try {
                        app(BroadcastService::class)->sendTest($this->payload($state), static::admin());
                    } catch (Throwable $e) {
                        Notification::make()->title('Test not sent')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Test sent to you')->body('Check your email and the bell on the site.')->success()->send();
                }),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        $raw = $this->form->getRawState();

        if (! empty($raw['save_template']) && filled($raw['template_name'] ?? null)) {
            RaffleNotificationTemplate::query()->updateOrCreate(
                ['bucket_type' => Str::limit(Str::slug($raw['template_name']), 50, '')],
                ['title' => $data['title'], 'body_text' => $data['body']],
            );
        }

        return app(BroadcastService::class)->send($this->payload($data), static::admin());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Sending — it will reach everyone within a few minutes.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
