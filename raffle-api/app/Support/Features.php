<?php

namespace App\Support;

/**
 * The growth and safety features' on/off switches (config/features.php,
 * Settings → On / off → New features).
 *
 * Off means: customers never see the feature at all, and the code behind
 * it does nothing. Admin screens for it stay reachable but carry an "Off"
 * label (offNotice / navigationBadge) pointing at the switch.
 */
final class Features
{
    public const LABELS = [
        'bank_name_check' => 'Bank-name check',
        'auto_payouts' => 'Automatic payouts',
        'reminders' => 'Reminders',
        'promo_codes' => 'Promo codes',
        'affiliates' => 'Affiliates',
        'abuse_detection' => 'Multi-account protection',
        'status_page' => 'Status page',
    ];

    public static function on(string $feature): bool
    {
        return (bool) config("features.{$feature}", false);
    }

    /**
     * The bank-name check is on AND Paystack is set up to answer it. With no
     * Paystack key, customers can still add an account by hand (it is then
     * marked unchecked and staff pay it by hand), instead of being locked out.
     */
    public static function bankNameCheck(): bool
    {
        return self::on('bank_name_check') && filled(config('services.paystack.secret_key'));
    }

    /** @return array<string, bool> every switch, for the customer pages */
    public static function all(): array
    {
        return array_map(fn ($key) => self::on($key), array_combine(array_keys(self::LABELS), array_keys(self::LABELS)));
    }

    /** "Off" for an admin menu item whose feature is switched off. */
    public static function navigationBadge(string $feature): ?string
    {
        return self::on($feature) ? null : 'Off';
    }

    /** One plain sentence for the top of an admin screen whose feature is off. */
    public static function offNotice(string $feature): ?string
    {
        if (self::on($feature)) {
            return null;
        }

        return 'Switched OFF: customers don\'t see '.self::LABELS[$feature].' and nothing runs. Switch it on in Settings → On / off → New features.';
    }
}
