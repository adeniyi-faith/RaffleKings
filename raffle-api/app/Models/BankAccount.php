<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $fillable = [
        'user_id', 'bank_name', 'bank_code', 'account_number', 'account_name', 'is_primary',
        'name_verified_at', 'paystack_recipient_code',
    ];

    protected $casts = ['is_primary' => 'boolean', 'name_verified_at' => 'datetime', 'removed_at' => 'datetime'];

    /**
     * Accounts the customer still has. A removed one that already received
     * a withdrawal is kept (the withdrawal points at it) but hidden.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('removed_at');
    }

    // Paystack's id for this account is only for the server.
    protected $hidden = ['paystack_recipient_code'];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    /** Paystack confirmed the name on this account (bank-name check). */
    public function isVerified(): bool
    {
        return $this->name_verified_at !== null && filled($this->bank_code);
    }
}
