<?php

namespace App\Services;

use App\Models\Legacy\WpUser;
use App\Models\ReferralClick;

/**
 * Resolves a referral code to a user, and records a real click — the
 * Laravel-side twin of referral-tracking.php's rk_track_referral_visit()
 * and RegistrationService::captureReferrer()'s lookup, kept as one place
 * so both agree on what a "valid" referral code looks like (a user's
 * user_login, or their rk_referral_code meta).
 */
class ReferralTrackingService
{
    public function resolveReferrer(string $code): ?WpUser
    {
        if ($code === '') {
            return null;
        }

        return WpUser::where('user_login', $code)
            ->orWhereHas('meta', fn ($q) => $q->where('meta_key', 'rk_referral_code')->where('meta_value', $code))
            ->first();
    }

    /**
     * One row per (referrer, visitor) pair, ever — a visitor reloading the
     * page or browsing further after landing on a referral link doesn't
     * inflate the click count. Safe to call repeatedly for the same pair.
     */
    public function recordClick(WpUser $referrer, string $visitorToken): void
    {
        ReferralClick::firstOrCreate([
            'referrer_user_id' => $referrer->ID,
            'visitor_token' => $visitorToken,
        ]);
    }
}
