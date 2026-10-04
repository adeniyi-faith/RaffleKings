<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money-safety audit, 4 October 2026 (B1, B5, B7, B9, B10, A3).
 *
 *  - ledger_journals: one row per money movement, with a unique business
 *    key (e.g. "deposit:42"), so the same payment can never be recorded
 *    twice even if a status check is ever missed.
 *  - ledger_system_entries: the business's side of each movement
 *    (gateway money in, promotions, prizes, payouts…). Together with the
 *    customer's side in wallet_ledger_entries, every journal adds up to
 *    zero. Kept in its own table so every existing report that sums
 *    customer entries keeps working unchanged.
 *  - wallets.held_balance: winnings waiting in a withdrawal request, shown
 *    separately instead of simply vanishing until the request is paid or
 *    rejected.
 *  - currency and effective_at on entries.
 *
 * Withdrawals already pending when this runs had their money taken from
 * winnings the old way; it is moved into held_balance here (with a
 * balanced journal) so paying or rejecting them works the new way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_journals', function (Blueprint $table) {
            $table->id();
            $table->string('business_key', 150)->unique();
            $table->string('reason', 60);
            $table->string('currency', 3)->default('NGN');
            $table->timestamp('effective_at')->useCurrent();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('reason');
        });

        Schema::create('ledger_system_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('ledger_journals');
            // gateway_clearing, ticket_sales, promotions, prizes, payouts,
            // referral_expense, affiliate_expense, adjustments, opening_balances
            $table->string('account', 40);
            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('NGN');
            $table->timestamp('created_at')->useCurrent();

            $table->index('account');
        });

        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->enum('balance_type', ['wallet', 'earnings', 'held'])->change();
            $table->unsignedBigInteger('journal_id')->nullable()->after('id');
            $table->string('currency', 3)->default('NGN')->after('amount');
            $table->timestamp('effective_at')->nullable()->after('reference_id');

            $table->index('journal_id');
        });

        Schema::table('wallets', function (Blueprint $table) {
            $table->decimal('held_balance', 12, 2)->default(0)->after('earnings_balance');
            $table->string('currency', 3)->default('NGN')->after('held_balance');
        });

        $this->holdPendingWithdrawals();
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['held_balance', 'currency']);
        });

        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['journal_id']);
            $table->dropColumn(['journal_id', 'currency', 'effective_at']);
        });

        Schema::dropIfExists('ledger_system_entries');
        Schema::dropIfExists('ledger_journals');
    }

    private function holdPendingWithdrawals(): void
    {
        if (! Schema::hasTable('withdrawal_requests')) {
            return;
        }

        DB::table('withdrawal_requests')->where('status', 'pending')->orderBy('id')->each(function ($w) {
            $amount = number_format((float) $w->amount_to_send, 2, '.', '');

            if ((float) $amount <= 0) {
                return;
            }

            DB::transaction(function () use ($w, $amount) {
                $now = now();
                $journalId = DB::table('ledger_journals')->insertGetId([
                    'business_key' => "withdrawal_hold:{$w->id}",
                    'reason' => 'withdrawal_hold',
                    'currency' => 'NGN',
                    'effective_at' => $now,
                    'created_at' => $now,
                ]);

                DB::table('wallet_ledger_entries')->insert([
                    'journal_id' => $journalId,
                    'user_id' => $w->user_id,
                    'balance_type' => 'held',
                    'direction' => 'credit',
                    'amount' => $amount,
                    'currency' => 'NGN',
                    'reason' => 'withdrawal_hold',
                    'description' => 'Pending withdrawal moved into held money when holds were introduced',
                    'reference_type' => 'withdrawal_request',
                    'reference_id' => $w->id,
                    'effective_at' => $now,
                    'created_at' => $now,
                ]);

                DB::table('ledger_system_entries')->insert([
                    'journal_id' => $journalId,
                    'account' => 'opening_balances',
                    'direction' => 'debit',
                    'amount' => $amount,
                    'currency' => 'NGN',
                    'created_at' => $now,
                ]);

                $exists = DB::table('wallets')->where('user_id', $w->user_id)->exists();

                if (! $exists) {
                    DB::table('wallets')->insert(['user_id' => $w->user_id, 'wallet_balance' => 0, 'earnings_balance' => 0, 'held_balance' => 0, 'created_at' => $now, 'updated_at' => $now]);
                }

                DB::table('wallets')->where('user_id', $w->user_id)->increment('held_balance', $amount);
            });
        });
    }
};
