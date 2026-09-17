<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 32 — the shadow-traffic period. A
 * REAL purchase already happened, entirely through the legacy PHP path
 * (wp/wp-content/mu-plugins/rk-core/api-financials.php's
 * rk_handle_payment_ai()) — nothing here ever settles money or allocates
 * tickets. Legacy PHP writes one "here's what actually happened" row
 * per wallet/earnings purchase (via a plain $wpdb->insert against this
 * table, no Laravel involved) right after its own COMMIT. Later,
 * App\Console\Commands\ProcessShadowPurchaseComparisons re-derives what
 * the NEW Laravel settlement path (TicketPricingService,
 * TicketPurchaseService) would have produced for the same inputs and
 * records whether it agrees — catching a pricing or allocation
 * divergence before item 33 lets Laravel actually settle real money.
 *
 * New table this app owns — normal down() is fine, and no wp_ prefix
 * (see App\Models\Legacy\LegacyModel's docblock for why prefixed
 * tables are treated differently).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shadow_purchase_comparisons', function (Blueprint $table) {
            $table->id();
            $table->unsignedMediumInteger('user_id');
            $table->unsignedInteger('raffle_id');
            $table->json('ticket_numbers');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->boolean('is_golden_box')->default(false);
            $table->string('funding_source', 20); // 'wallet' | 'earnings'
            $table->decimal('legacy_charged_amount', 12, 2);
            $table->decimal('legacy_new_balance', 12, 2);

            // Filled in by ProcessShadowPurchaseComparisons once compared.
            $table->string('status', 20)->default('pending'); // 'pending' | 'compared'
            $table->decimal('laravel_expected_price', 12, 2)->nullable();
            $table->boolean('price_mismatch')->nullable();
            $table->decimal('laravel_wallet_balance', 12, 2)->nullable();
            $table->boolean('entries_missing')->nullable();
            $table->json('mismatch_details')->nullable();
            $table->timestamp('compared_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
            $table->index(['user_id', 'raffle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_purchase_comparisons');
    }
};
