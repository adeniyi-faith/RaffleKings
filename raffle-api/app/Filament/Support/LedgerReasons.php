<?php

namespace App\Filament\Support;

/** Plain-English names for wallet ledger reasons (money movements). */
final class LedgerReasons
{
    public const LABELS = [
        'deposit' => 'Top-up',
        'signup_bonus' => 'Sign-up bonus',
        'ticket_purchase' => 'Tickets bought',
        'ticket_purchase_refunded' => 'Ticket purchase refunded',
        'prize_payout' => 'Prize won',
        'referral_commission' => 'Referral commission',
        'withdrawal_request' => 'Withdrawal',
        'withdrawal_rejected' => 'Withdrawal refunded',
        'points_redemption' => 'Points cashed in',
        'admin_adjustment' => 'Admin adjustment',
        'opening_balance' => 'Opening balance (from old site)',
        'deposit_bonus' => 'Top-up bonus',
        'transaction_revoked' => 'Reversed by admin',
    ];

    public static function label(?string $reason): string
    {
        return self::LABELS[$reason] ?? ucfirst(str_replace('_', ' ', (string) $reason));
    }
}
