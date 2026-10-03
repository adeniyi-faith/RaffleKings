<?php

namespace App\Notifications;

use App\Models\Broadcast;
use App\Models\Legacy\WpUser;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Contracts\TracksDelivery;
use App\Services\Retention\DeliveryTracker;
use App\Services\Messaging\BroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** The email / phone-notification copy of a message to customers (Site → Message customers). */
class BroadcastMessage extends Notification implements ShouldQueue, TracksDelivery
{
    use Queueable;

    /** @var array<string, ?string> */
    private array $tokens = [];

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    /** @param  list<string>  $channels  email and/or push */
    public function __construct(public readonly Broadcast $broadcast, public readonly array $channels) {}

    public function via(mixed $notifiable): array
    {
        return array_values(array_filter([
            in_array('email', $this->channels, true) ? 'mail' : null,
            in_array('push', $this->channels, true) ? OneSignalChannel::class : null,
        ]));
    }

    /** This customer's tracking token for the channel (none for a test send). */
    public function deliveryToken(string $channel, mixed $notifiable): ?string
    {
        if (! $this->broadcast->id) {
            return null;
        }

        return $this->tokens[$channel.':'.$notifiable->getKey()] ??= DeliveryTracker::tokenFor('broadcast', $this->broadcast->id, (int) $notifiable->getKey(), $channel);
    }

    public function toMail(WpUser $notifiable): MailMessage
    {
        $b = $this->broadcast;
        $token = $this->deliveryToken('email', $notifiable);
        $mail = (new MailMessage)
            ->subject(BroadcastService::personalise($b->title, $notifiable))
            ->greeting('Hi '.BroadcastService::firstName($notifiable).',');

        foreach (preg_split("/\n\s*\n/", BroadcastService::personalise($b->body, $notifiable)) as $paragraph) {
            $mail->line(trim($paragraph));
        }

        if ($b->link_url) {
            $mail->action($b->link_label ?: 'Open', $token ? DeliveryTracker::linkUrl($token) : BroadcastService::absolute($b->link_url));
        }

        if ($token) {
            $mail->line(DeliveryTracker::pixel($token));
        }

        return $mail;
    }

    public function toOneSignal(WpUser $notifiable): array
    {
        $token = $this->deliveryToken('push', $notifiable);

        return array_filter([
            'headings' => ['en' => BroadcastService::personalise($this->broadcast->title, $notifiable)],
            'contents' => ['en' => Str::limit(BroadcastService::personalise($this->broadcast->body, $notifiable), 180)],
            'url' => $token ? DeliveryTracker::linkUrl($token) : ($this->broadcast->link_url ? BroadcastService::absolute($this->broadcast->link_url) : null),
        ]);
    }
}
