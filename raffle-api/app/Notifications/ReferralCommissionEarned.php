<?php

namespace App\Notifications;

use App\Models\ReferralCommission;
use App\Notifications\Channels\OneSignalChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 35d — tells a referrer they just
 * earned a commission, replacing the legacy site's synchronous Telegram
 * ping in rk_process_referral_commission() with a queued, retried,
 * user-facing notification (the legacy version only alerted admins via
 * Telegram — the referrer themselves was never told directly). Fired
 * from App\Services\ReferralCommissionService::payCommissionForFirstDeposit().
 */
class ReferralCommissionEarned extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(private readonly ReferralCommission $commission) {}

    public function via(mixed $notifiable): array
    {
        return ['mail', OneSignalChannel::class];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You earned a referral commission! 🤝')
            ->greeting('Nice work!')
            ->line('₦'.number_format((float) $this->commission->commission_amount).' has been added to your earnings balance from a referral.');
    }

    public function toOneSignal(mixed $notifiable): array
    {
        return [
            'headings' => ['en' => 'Referral commission earned 🤝'],
            'contents' => ['en' => '₦'.number_format((float) $this->commission->commission_amount).' added to your earnings.'],
        ];
    }
}
