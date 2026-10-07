<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/** Adding a bank account needs the emailed code; tests put a known one in place. */
trait ConfirmsBankAccountCode
{
    protected function addBankAccount(array $data, ?int $userId = null)
    {
        $userId ??= $this->lastActingUserId ?? Auth::guard('wordpress')->id();

        Cache::put("bank-account-code:{$userId}", ['hash' => Hash::make('123456'), 'wrong' => 0], now()->addMinutes(10));

        return $this->postJson('/api/bank-accounts', $data + ['code' => '123456']);
    }
}
