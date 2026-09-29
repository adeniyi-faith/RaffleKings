<?php

namespace App\Notifications;

use App\Models\Broadcast;
use App\Models\Legacy\WpUser;
use App\Notifications\Channels\OneSignalChannel;
use App\Services\Messaging\BroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** The email / phone-notification copy of a message to customers (Site → Message customers). */
class BroadcastMessage extends Notification implements ShouldQueue
{
    use Queueable;

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

    public function toMail(WpUser $notifiable): MailMessage
    {
        $b = $this->broadcast;
        $mail = (new MailMessage)
            ->subject(BroadcastService::personalise($b->title, $notifiable))
            ->greeting('Hi '.BroadcastService::firstName($notifiable).',');

        foreach (preg_split("/\n\s*\n/", BroadcastService::personalise($b->body, $notifiable)) as $paragraph) {
            $mail->line(trim($paragraph));
        }

        if ($b->link_url) {
            $mail->action($b->link_label ?: 'Open', BroadcastService::absolute($b->link_url));
        }

        return $mail;
    }

    public function toOneSignal(WpUser $notifiable): array
    {
        return array_filter([
            'headings' => ['en' => BroadcastService::personalise($this->broadcast->title, $notifiable)],
            'contents' => ['en' => Str::limit(BroadcastService::personalise($this->broadcast->body, $notifiable), 180)],
            'url' => $this->broadcast->link_url ? BroadcastService::absolute($this->broadcast->link_url) : null,
        ]);
    }
}
