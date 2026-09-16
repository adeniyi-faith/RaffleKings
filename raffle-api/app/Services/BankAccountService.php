<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bank account management on the new, real `bank_accounts` table —
 * replacing the `rk_bank_accounts` serialized array previously stored as
 * a single wp_usermeta row (audit TD-26). Keeps the same business rules
 * the legacy site already enforces (max 2 accounts, Nigerian 10-digit
 * account numbers, auto-promoting a replacement primary on delete), so
 * behaviour doesn't change for users during the migration — see
 * wp/wp-content/mu-plugins/rk-core/api-auth.php's rk_save_bank_account()/
 * rk_delete_bank_account() for the rules this mirrors.
 */
class BankAccountService
{
    private const MAX_ACCOUNTS_PER_USER = 2;

    public function list(WpUser $user): Collection
    {
        return BankAccount::query()->where('user_id', $user->ID)->orderByDesc('is_primary')->get();
    }

    /**
     * @throws InvalidArgumentException if the format is invalid
     * @throws RuntimeException if the user already has the maximum number of accounts
     */
    public function add(WpUser $user, string $bankName, string $accountNumber, string $accountName): BankAccount
    {
        if (strlen($bankName) < 2) {
            throw new InvalidArgumentException('Enter a valid Nigerian bank name.');
        }

        if (! preg_match('/^[0-9]{10}$/', $accountNumber)) {
            throw new InvalidArgumentException('Nigerian account numbers must be exactly 10 digits.');
        }

        if (! preg_match('/^[A-Za-z .\'-]{3,80}$/', $accountName)) {
            throw new InvalidArgumentException('Enter the account name as it appears at the bank.');
        }

        return DB::transaction(function () use ($user, $bankName, $accountNumber, $accountName) {
            $existingCount = BankAccount::query()->where('user_id', $user->ID)->lockForUpdate()->count();

            if ($existingCount >= self::MAX_ACCOUNTS_PER_USER) {
                throw new RuntimeException('Maximum of '.self::MAX_ACCOUNTS_PER_USER.' bank accounts allowed.');
            }

            return BankAccount::create([
                'user_id' => $user->ID,
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'account_name' => $accountName,
                'is_primary' => $existingCount === 0,
            ]);
        });
    }

    /**
     * @throws RuntimeException if the account doesn't belong to this user
     */
    public function setPrimary(WpUser $user, int $accountId): BankAccount
    {
        return DB::transaction(function () use ($user, $accountId) {
            $account = BankAccount::query()->where('user_id', $user->ID)->whereKey($accountId)->lockForUpdate()->first();

            if (! $account) {
                throw new RuntimeException('Bank account not found.');
            }

            BankAccount::query()->where('user_id', $user->ID)->where('id', '!=', $accountId)->update(['is_primary' => false]);
            $account->update(['is_primary' => true]);

            return $account->refresh();
        });
    }

    /**
     * Deleting the primary account automatically promotes another one of
     * the user's remaining accounts, if any — mirrors the legacy delete
     * handler's behaviour so a user is never left with accounts but no
     * primary.
     *
     * @throws RuntimeException if the account doesn't belong to this user
     */
    public function delete(WpUser $user, int $accountId): void
    {
        DB::transaction(function () use ($user, $accountId) {
            $account = BankAccount::query()->where('user_id', $user->ID)->whereKey($accountId)->lockForUpdate()->first();

            if (! $account) {
                throw new RuntimeException('Bank account not found.');
            }

            $wasPrimary = $account->is_primary;
            $account->delete();

            if ($wasPrimary) {
                BankAccount::query()->where('user_id', $user->ID)->oldest('id')->first()?->update(['is_primary' => true]);
            }
        });
    }
}
