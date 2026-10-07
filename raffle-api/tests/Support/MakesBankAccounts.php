<?php

namespace Tests\Support;

use App\Models\BankAccount;

/** A bank account added days ago, so it's past the 24-hour wait before it can receive a withdrawal. */
trait MakesBankAccounts
{
    protected function oldBankAccount(array $attributes): BankAccount
    {
        $account = BankAccount::create($attributes);
        BankAccount::query()->whereKey($account->id)->update(['created_at' => now()->subDays(3)]);

        return $account->refresh();
    }
}
