<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $fillable = [
        'user_id', 'bank_name', 'bank_code', 'account_number', 'account_number_hash', 'account_name', 'is_primary',
        'name_verified_at', 'name_mismatch', 'paystack_recipient_code',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'name_verified_at' => 'datetime',
        'removed_at' => 'datetime',
        'name_mismatch' => 'boolean',
        // Stored encrypted (money-safety audit H13). Search with account_number_hash.
        'account_number' => 'encrypted',
    ];

    protected static function booted(): void
    {
        static::saving(function (BankAccount $account) {
            if ($account->isDirty('account_number') || ! $account->account_number_hash) {
                $account->account_number_hash = static::hashNumber((string) $account->account_number);
            }
        });
    }

    /** A keyed fingerprint of an account number, for matching without reading it. */
    public static function hashNumber(string $number): string
    {
        return hash_hmac('sha256', $number, 'bank-account|'.config('app.key'));
    }

    /** ••••••6789: what staff screens show unless someone asks to see the whole number. */
    public function masked(): string
    {
        $number = (string) $this->account_number;

        return str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4);
    }

    /**
     * Accounts the customer still has. A removed one that already received
     * a withdrawal is kept (the withdrawal points at it) but hidden.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('removed_at');
    }

    // Paystack's id for this account is only for the server.
    protected $hidden = ['paystack_recipient_code', 'account_number_hash', 'name_mismatch'];

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
