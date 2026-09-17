<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    protected $fillable = [
        'user_id',
        'bank_account_id',
        'requested_amount',
        'fee_amount',
        'amount_to_send',
        'status',
        'legacy_transaction_id',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'amount_to_send' => 'decimal:2',
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
