<?php

namespace App\Filament\Resources\BroadcastResource\Pages;

use App\Filament\Concerns\RunsAdminActions;
use App\Filament\Resources\BroadcastResource;
use App\Models\Legacy\RaffleNotificationTemplate;
use App\Services\Messaging\Audience;
use App\Services\Messaging\BroadcastService;
use App\Services\Retention\MemberSegments;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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
            'audience_options' => BroadcastResource::audienceOptions($state),
            'is_promotion' => (bool) ($state['is_promotion'] ?? false),
            'scheduled_at' => ($state['when'] ?? 'now') === 'later' ? ($state['scheduled_at'] ?? null) : null,
        ];
    }

    /** The Customers list hands over its ticked customers as a saved list (see WpUserResource). */
    public function mount(): void
    {
        parent::mount();

        // "Message them" on Growth → Member segments.
        $segment = (string) request()->query('segment');
        $flag = (string) request()->query('flag');

        if (isset(MemberSegments::SEGMENTS[$segment]) || isset(MemberSegments::FLAGS[$flag])) {
            $this->form->fill([
                'audience' => isset(MemberSegments::SEGMENTS[$segment]) ? 'segment' : 'custom',
                'segments' => isset(MemberSegments::SEGMENTS[$segment]) ? [$segment] : [],
                'filters' => isset(MemberSegments::FLAGS[$flag]) && ! isset(MemberSegments::SEGMENTS[$segment]) ? ['flags' => [$flag]] : [],
                'flags' => isset(MemberSegments::FLAGS[$flag]) && isset(MemberSegments::SEGMENTS[$segment]) ? [$flag] : [],
                'channels' => ['inbox', 'email'],
                'is_promotion' => true,
                'when' => 'now',
                'user_ids' => [],
            ]);
        }

        if ($token = request()->query('list')) {
            $ids = array_map('intval', (array) Cache::get(BroadcastResource::TICKED_CACHE_PREFIX.$token, []));

            if ($ids !== []) {
                $this->form->fill(['audience' => 'selected', 'user_ids' => $ids]);
            }
        }
    }

    private function recipients(): int
    {
        $p = $this->payload($this->data + ['title' => '', 'body' => '']);

        return app(Audience::class)->count((string) $p['audience'], $p['audience_options'], $p['is_promotion']);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label(fn () => ($this->data['when'] ?? 'now') === 'later' ? 'Schedule' : 'Send')
            ->icon('heroicon-o-paper-airplane')
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading(fn () => (($this->data['when'] ?? 'now') === 'later' ? 'Schedule for ' : 'Send to ').number_format($this->recipients()).' customers?')
            ->modalDescription(fn () => ($this->data['when'] ?? 'now') === 'later'
                ? 'It goes out at the time you chose. Until then you can change the time or cancel it. The group is worked out again when it goes out, so the number can change.'
                : 'This can\'t be undone. Emails and notifications go out over the next few minutes.')
            ->modalSubmitActionLabel(fn () => ($this->data['when'] ?? 'now') === 'later' ? 'Yes, schedule it' : 'Yes, send it')
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

        return app(BroadcastService::class)->send($this->payload($data + ['when' => $raw['when'] ?? 'now', 'is_promotion' => $raw['is_promotion'] ?? false]), static::admin());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->getRecord()->status === 'scheduled' ? 'Scheduled. It goes out at the time you chose.' : 'Sending. It will reach everyone within a few minutes.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
