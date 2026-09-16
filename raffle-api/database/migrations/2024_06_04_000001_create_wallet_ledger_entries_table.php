<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The real double-entry-style ledger the audit recommends (§11/§21): an
 * APPEND-ONLY record of every credit/debit against a user's wallet or
 * earnings balance. Nothing here is ever updated or deleted — that's
 * what makes "how did this balance get to this number" always
 * reconstructable, which the old system (a single mutable usermeta
 * number, then a single mutable `wallets` row) never could answer.
 *
 * The `wallets` table stays as the fast-read "current balance" cache —
 * see App\Services\WalletLedgerService — it is always meant to equal
 * the sum of this table's entries for that user/balance_type, and
 * WalletLedgerService::reconstructBalance() proves that on demand.
 *
 * New table this app owns — normal down() is fine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id');
            $table->enum('balance_type', ['wallet', 'earnings']);
            $table->enum('direction', ['credit', 'debit']);
            $table->decimal('amount', 12, 2); // always positive; direction says which way it moved

            // What kind of event this was (ticket_purchase, deposit,
            // withdrawal, referral_commission, admin_adjustment,
            // points_redemption, transfer, opening_balance, ...) — a
            // short machine-readable tag, not free text.
            $table->string('reason', 60);

            $table->string('description')->nullable();

            // Loose polymorphic link back to whatever caused this entry
            // (e.g. a wp_raffle_transactions row) — intentionally not a
            // real foreign key, since the referenced record may live in
            // a legacy table this app doesn't own.
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'balance_type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
