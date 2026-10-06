<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money-safety audit, 4 October 2026 (H13, H10).
 *
 *  - Bank account numbers are stored encrypted. The column is widened to hold
 *    the encrypted text, and a keyed hash (account_number_hash) is kept
 *    beside it so "is this number already saved?" and "do two customers share
 *    a number?" still work without reading the numbers.
 *  - name_mismatch: the bank's name for the account doesn't look like the
 *    customer's own name; staff are told and automatic payout is blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['account_number']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->text('account_number')->change();
            $table->char('account_number_hash', 64)->nullable()->after('account_number')->index();
            $table->boolean('name_mismatch')->default(false)->after('name_verified_at');
        });

        DB::table('bank_accounts')->orderBy('id')->each(function ($row) {
            $plain = (string) $row->account_number;

            // Already encrypted (a rerun): leave it alone.
            if (strlen($plain) > 40) {
                return;
            }

            DB::table('bank_accounts')->where('id', $row->id)->update([
                'account_number' => Crypt::encryptString($plain),
                'account_number_hash' => hash_hmac('sha256', $plain, 'bank-account|'.config('app.key')),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('bank_accounts')->orderBy('id')->each(function ($row) {
            try {
                DB::table('bank_accounts')->where('id', $row->id)->update(['account_number' => Crypt::decryptString($row->account_number)]);
            } catch (\Throwable) {
                // not encrypted
            }
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['account_number_hash', 'name_mismatch']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->string('account_number', 10)->change();
            $table->index('account_number');
        });
    }
};
