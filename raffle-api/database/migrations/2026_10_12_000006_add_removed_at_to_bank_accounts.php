<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bank account a customer removes after it received a withdrawal can't
 * be deleted: the withdrawal keeps pointing at it (that's the record of
 * where the money went), and the database refuses. Deleting used to fail
 * with a server error ("Failed to delete."). Now such an account is marked
 * removed instead: hidden from the customer, kept for staff and history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_accounts', 'removed_at')) {
                $table->timestamp('removed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
