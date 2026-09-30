<?php

namespace App\Console\Commands;

use App\Services\Reminders\ReminderService;
use Illuminate\Console\Command;

/** Runs every five minutes from the scheduler (routes/console.php). */
class SendReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Send "raffle ends soon" and "you left tickets in checkout" reminders';

    public function handle(ReminderService $reminders): int
    {
        $sent = $reminders->run();

        $this->info("Raffle ending: {$sent['raffle_ending']}. Checkout: {$sent['abandoned_checkout']}.");

        return self::SUCCESS;
    }
}
