<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    use \App\Models\Concerns\ChecksStatusFlow;

    /** Waiting → paid or turned down. Once decided, it stays decided. */
    public const STATUS_FLOW = ['pending' => ['paid', 'rejected'], 'paid' => [], 'rejected' => []];

    protected $fillable = [
        'user_id',
        'bank_account_id',
        'requested_amount',
        'fee_amount',
        'amount_to_send',
        'status',
        'idempotency_key',
        'legacy_transaction_id',
        // Automatic payouts (App\Services\PayoutService).
        'payout_status',
        'payout_reference',
        'payout_transfer_code',
        'payout_error',
        'payout_attempts',
        'payout_started_at',
        'payout_started_by',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'amount_to_send' => 'decimal:2',
        'payout_started_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }
}
