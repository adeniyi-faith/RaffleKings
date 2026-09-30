<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gaming tax (App\Services\GamingTaxService): one row per month once staff
 * lock it. A locked month keeps the exact figures, the rate and the
 * shortfall rule it was locked with, so it never changes afterwards even if
 * the settings do. Open (not yet locked) months are worked out live and are
 * not stored here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gaming_tax_periods')) {
            Schema::create('gaming_tax_periods', function (Blueprint $table) {
                $table->id();
                $table->char('period', 7)->unique(); // 2026-09

                $table->decimal('sales', 14, 2);         // ticket sales in the month
                $table->decimal('refunds', 14, 2);       // ticket money given back
                $table->decimal('prizes', 14, 2);        // prizes won (awarded) in the month
                $table->decimal('carried_in', 14, 2)->default(0);   // shortfall brought from earlier months
                $table->decimal('taxable', 14, 2);       // what the tax is charged on
                $table->decimal('carried_out', 14, 2)->default(0);  // shortfall passed to the next month
                $table->decimal('rate', 6, 3);           // percent, as it was when locked
                $table->string('shortfall_rule', 20);    // zero | carry_forward, as it was when locked
                $table->decimal('tax_due', 14, 2);
                $table->json('by_raffle')->nullable();   // the per-raffle table as it was when locked

                $table->string('status', 10)->default('locked'); // locked | filed | paid
                $table->timestamp('locked_at')->nullable();
                $table->unsignedBigInteger('locked_by')->nullable();
                $table->timestamp('filed_at')->nullable();
                $table->unsignedBigInteger('filed_by')->nullable();
                $table->string('filing_reference', 120)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->decimal('paid_amount', 14, 2)->nullable();
                $table->string('payment_reference', 120)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gaming_tax_periods');
    }
};
