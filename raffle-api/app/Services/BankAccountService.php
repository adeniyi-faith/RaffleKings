<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\WithdrawalRequest;
use App\Services\Payments\PaystackApi;
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

    public function __construct(private readonly PaystackApi $paystack) {}

    public function list(WpUser $user): Collection
    {
        return BankAccount::query()->active()->where('user_id', $user->ID)->orderByDesc('is_primary')->get();
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

        return $this->create($user, ['bank_name' => $bankName, 'account_number' => $accountNumber, 'account_name' => $accountName]);
    }

    /**
     * Bank-name check (Settings → On / off → New features): asks Paystack
     * which name the bank holds for this number, before anything is saved.
     *
     * @return array{bank_code: string, bank_name: string, account_number: string, account_name: string}
     *
     * @throws InvalidArgumentException when the bank or number is wrong
     * @throws PaymentGatewayException when Paystack can't be asked right now
     */
    public function lookUp(string $bankCode, string $accountNumber): array
    {
        if (! preg_match('/^[0-9]{10}$/', $accountNumber)) {
            throw new InvalidArgumentException('Nigerian account numbers must be exactly 10 digits.');
        }

        $bankName = $this->paystack->bankName($bankCode);

        if (! $bankName) {
            throw new InvalidArgumentException('Choose your bank from the list.');
        }

        $accountName = $this->paystack->resolveAccountName($accountNumber, $bankCode);

        if (! $accountName) {
            throw new InvalidArgumentException("{$bankName} has no account {$accountNumber}. Check the number and the bank.");
        }

        return [
            'bank_code' => $bankCode,
            'bank_name' => mb_substr($bankName, 0, 100),
            'account_number' => $accountNumber,
            'account_name' => mb_substr($accountName, 0, 80),
        ];
    }

    /**
     * Saves an account whose name Paystack has just confirmed. The name is
     * the bank's, never what the customer typed.
     *
     * @throws InvalidArgumentException|PaymentGatewayException see lookUp()
     * @throws RuntimeException if the user already has the maximum number of accounts
     */
    public function addVerified(WpUser $user, string $bankCode, string $accountNumber): BankAccount
    {
        return $this->create($user, $this->lookUp($bankCode, $accountNumber) + ['name_verified_at' => now()]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(WpUser $user, array $attributes): BankAccount
    {
        return DB::transaction(function () use ($user, $attributes) {
            $existingCount = BankAccount::query()->active()->where('user_id', $user->ID)->lockForUpdate()->count();

            if ($existingCount >= self::MAX_ACCOUNTS_PER_USER) {
                throw new RuntimeException('Maximum of '.self::MAX_ACCOUNTS_PER_USER.' bank accounts allowed.');
            }

            if (BankAccount::query()->active()->where('user_id', $user->ID)->where('account_number', $attributes['account_number'])->exists()) {
                throw new RuntimeException('This account is already saved.');
            }

            return BankAccount::create($attributes + [
                'user_id' => $user->ID,
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
            $account = BankAccount::query()->active()->where('user_id', $user->ID)->whereKey($accountId)->lockForUpdate()->first();

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
     * @throws InvalidArgumentException while a withdrawal to it is still waiting to be paid
     */
    public function delete(WpUser $user, int $accountId): void
    {
        DB::transaction(function () use ($user, $accountId) {
            $account = BankAccount::query()->active()->where('user_id', $user->ID)->whereKey($accountId)->lockForUpdate()->first();

            if (! $account) {
                throw new RuntimeException('Bank account not found.');
            }

            if (WithdrawalRequest::query()->where('bank_account_id', $account->id)->where('status', 'pending')->exists()) {
                throw new InvalidArgumentException('A withdrawal to this account is still being paid. You can remove it once that withdrawal is paid or declined.');
            }

            $wasPrimary = $account->is_primary;

            // An account that already received a withdrawal is the record of
            // where that money went, so it's hidden rather than deleted. The
            // database refuses to delete it, which used to show "Failed to delete".
            if (WithdrawalRequest::query()->where('bank_account_id', $account->id)->exists()) {
                $account->forceFill(['removed_at' => now(), 'is_primary' => false])->save();
            } else {
                $account->delete();
            }

            if ($wasPrimary) {
                BankAccount::query()->active()->where('user_id', $user->ID)->oldest('id')->first()?->update(['is_primary' => true]);
            }
        });
    }
}
