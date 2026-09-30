<?php

namespace App\Console\Commands;

use App\Services\Auth\StaffTwoStep;
use App\Settings\SettingsStore;
use Illuminate\Console\Command;

/**
 * The way back in if email breaks while two-step staff sign-in is on and
 * nobody can receive a code: `php artisan staff:two-step off`. It can only
 * switch it OFF (switching it on needs the admin, which first proves email
 * works). Anyone with access to the server can run it, which is the point.
 */
class StaffTwoStepCommand extends Command
{
    protected $signature = 'staff:two-step {state=status : "off" to switch two-step staff sign-in off, or "status"}';

    protected $description = 'Show, or switch off, two-step sign-in for staff (emergency use)';

    public function handle(): int
    {
        $state = $this->argument('state');

        if ($state === 'off') {
            SettingsStore::save([StaffTwoStep::SETTING => false], null);
            $this->info('Two-step sign-in for staff is now OFF. Staff can sign in with just their password.');

            return self::SUCCESS;
        }

        if ($state !== 'status') {
            $this->error('Use "off" or "status". (To switch it on, use Settings → Security in the admin.)');

            return self::INVALID;
        }

        $this->line('Two-step sign-in for staff is '.(StaffTwoStep::enabled() ? 'ON' : 'OFF').'.');

        return self::SUCCESS;
    }
}
