<?php

namespace App\Services;

use App\Models\Legacy\WpUser;
use App\Notifications\BankAccountCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A second proof before a bank account can be added (money-safety audit
 * H4): a 6-digit code emailed to the account owner. Single use, good for
 * 10 minutes, tied to this customer, and limited to a few wrong guesses and
 * a few emails. Someone who only has a stolen password or login cookie can't
 * add their own bank account to withdraw to.
 */
class BankAccountStepUp
{
    public const MINUTES = 10;

    public const MAX_WRONG = 5;

    public const MAX_SENDS_PER_HOUR = 4;

    private function key(int $userId): string
    {
        return "bank-account-code:{$userId}";
    }

    public function send(WpUser $user): void
    {
        $sends = Cache::get("bank-account-code-sends:{$user->ID}", 0);

        if ($sends >= self::MAX_SENDS_PER_HOUR) {
            throw new RuntimeException('Too many codes asked for. Please try again in an hour.');
        }

        Cache::put("bank-account-code-sends:{$user->ID}", $sends + 1, now()->addHour());

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($user->ID), ['hash' => Hash::make($code), 'wrong' => 0], now()->addMinutes(self::MINUTES));

        $user->notify(new BankAccountCode($code, self::MINUTES));
    }

    /**
     * Checks the code and uses it up. @throws RuntimeException with a message for the customer
     */
    public function consume(WpUser $user, ?string $code): void
    {
        $pending = Cache::get($this->key($user->ID));

        if (! $pending) {
            throw new RuntimeException('Ask for a code first. We email a 6-digit code to confirm it is you.');
        }

        if (! is_string($code) || ! preg_match('/^[0-9]{6}$/', $code) || ! Hash::check($code, $pending['hash'])) {
            $pending['wrong']++;

            if ($pending['wrong'] >= self::MAX_WRONG) {
                Cache::forget($this->key($user->ID));
                throw new RuntimeException('Too many wrong codes. Ask for a new code.');
            }

            Cache::put($this->key($user->ID), $pending, now()->addMinutes(self::MINUTES));

            throw new RuntimeException('That code is not right.');
        }

        Cache::forget($this->key($user->ID));
    }
}
