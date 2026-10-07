<?php

/*
|--------------------------------------------------------------------------
| Register of the legal and policy numbers the app relies on
|--------------------------------------------------------------------------
|
| Each number that comes from a rule, a regulator or a business decision
| is listed here with where it came from and when it took effect, so
| nobody has to guess later why it is what it is. Where the source has not
| been confirmed yet it says so plainly: those are the items to put in
| front of a lawyer or accountant. `php artisan compliance:values` prints it.
|
| This list describes the numbers; the numbers themselves are still set where
| they always were (the `config` key on each line).
*/

return [
    'values' => [
        ['key' => 'gaming_tax.rate', 'label' => 'Gaming tax rate (%)', 'config' => 'gaming_tax.rate', 'source' => 'NEEDS CONFIRMATION: the rate and who it applies to', 'effective_from' => null, 'confirmed_by' => null],
        ['key' => 'withdrawals.minimum_amount', 'label' => 'Smallest withdrawal (₦)', 'config' => 'withdrawals.minimum_amount', 'source' => 'Business decision', 'effective_from' => '2024-01-01', 'confirmed_by' => 'Business'],
        ['key' => 'withdrawals.verification_fee', 'label' => 'First-withdrawal verification fee (₦)', 'config' => 'withdrawals.verification_fee', 'source' => 'Business decision', 'effective_from' => '2024-01-01', 'confirmed_by' => 'Business'],
        ['key' => 'withdrawals.new_account_wait_hours', 'label' => 'Wait before a new bank account can be paid (hours)', 'config' => 'withdrawals.new_account_wait_hours', 'source' => 'Safety rule (money-safety audit)', 'effective_from' => '2026-10-24', 'confirmed_by' => 'Business'],
        ['key' => 'ledger.adjustment_approval_over', 'label' => 'Balance changes above this need a second person (₦)', 'config' => 'ledger.adjustment_approval_over', 'source' => 'Business decision, 2026-10-04', 'effective_from' => '2026-10-24', 'confirmed_by' => 'Business'],
        ['key' => 'referrals.commission_rate', 'label' => 'Referral commission rate', 'config' => 'referrals.commission_rate', 'source' => 'Business decision', 'effective_from' => '2024-01-01', 'confirmed_by' => 'Business'],
        ['key' => 'age_rule', 'label' => 'Minimum age to play', 'config' => null, 'source' => 'NEEDS CONFIRMATION: the licence or law that sets it', 'effective_from' => null, 'confirmed_by' => null],
        ['key' => 'identity_checks', 'label' => 'When identity (KYC) checks are required', 'config' => null, 'source' => 'NEEDS CONFIRMATION: what the licence requires', 'effective_from' => null, 'confirmed_by' => null],
        ['key' => 'sanctions_screening', 'label' => 'Sanctions and watch-list screening', 'config' => null, 'source' => 'NEEDS CONFIRMATION: what is required for this business', 'effective_from' => null, 'confirmed_by' => null],
    ],
];
