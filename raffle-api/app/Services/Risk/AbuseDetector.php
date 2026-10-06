<?php

namespace App\Services\Risk;

use App\Models\BankAccount;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserDevice;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Multi-account protection (Settings → On / off → New features): spots two
 * accounts that look like one person, by what they share:
 *
 *  - a bank account number (the strongest sign: money goes to one place),
 *  - a phone number (from Edit Profile),
 *  - a browser (the random rk_did cookie; see RememberDevice).
 *
 * Used to HOLD referral commission and affiliate earnings for staff to
 * check, never to ban anyone automatically: families and shared phones
 * are real, so a person decides (Users → Fraud watch).
 */
class AbuseDetector
{
    public const DEVICE_COOKIE = 'rk_did';

    /** A random id a browser was given, if it looks like one of ours. */
    public static function deviceIdFrom(Request $request): ?string
    {
        $id = (string) $request->cookie(self::DEVICE_COOKIE);

        return preg_match('/^[a-f0-9-]{16,64}$/', $id) ? $id : null;
    }

    /** Remembers that this customer used this browser (at most once an hour). */
    public function recordDevice(int $userId, Request $request): void
    {
        $deviceId = self::deviceIdFrom($request);

        if (! $deviceId || ! Features::on('abuse_detection')) {
            return;
        }

        try {
            if (! Cache::add("device-seen:{$userId}:{$deviceId}", 1, now()->addHour())) {
                return;
            }

            $row = UserDevice::query()->firstOrNew(['user_id' => $userId, 'device_id' => $deviceId]);
            $row->first_seen_at ??= now();
            $row->last_seen_at = now();
            $row->ip = $request->ip();
            $row->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Why these two accounts look like one person, in plain words, or
     * null when nothing links them.
     */
    public function linkBetween(int $a, int $b): ?string
    {
        if ($a === $b) {
            return 'It is the same account.';
        }

        $sharedBank = BankAccount::query()->where('user_id', $a)
            ->whereIn('account_number_hash', BankAccount::query()->where('user_id', $b)->select('account_number_hash'))
            ->first();

        if ($sharedBank) {
            return "Both accounts saved bank account {$sharedBank->masked()}.";
        }

        $phoneA = self::phoneKey(WpUserMeta::query()->where('user_id', $a)->where('meta_key', 'phone')->value('meta_value'));
        $phoneB = self::phoneKey(WpUserMeta::query()->where('user_id', $b)->where('meta_key', 'phone')->value('meta_value'));

        if ($phoneA && $phoneA === $phoneB) {
            return 'Both accounts have the same phone number.';
        }

        $sharedDevice = UserDevice::query()->where('user_id', $a)
            ->whereIn('device_id', UserDevice::query()->where('user_id', $b)->select('device_id'))
            ->exists();

        return $sharedDevice ? 'Both accounts were used on the same phone or computer.' : null;
    }

    /**
     * Customers who share a browser.
     *
     * @return Collection<int, array{key: string, user_ids: list<int>}>
     */
    public function sharedDevices(): Collection
    {
        $ids = UserDevice::query()->select('device_id')->groupBy('device_id')->havingRaw('COUNT(DISTINCT user_id) > 1')->limit(200)->pluck('device_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return UserDevice::query()->whereIn('device_id', $ids)->get()->groupBy('device_id')
            ->map(fn (Collection $rows, string $id) => ['key' => substr($id, 0, 8), 'user_ids' => $rows->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all()])
            ->values();
    }

    /**
     * Customers who gave the same phone number.
     *
     * @return Collection<int, array{key: string, user_ids: list<int>}>
     */
    public function sharedPhones(): Collection
    {
        return WpUserMeta::query()->where('meta_key', 'phone')->where('meta_value', '!=', '')->get(['user_id', 'meta_value'])
            ->groupBy(fn ($row) => self::phoneKey($row->meta_value) ?? '')
            ->reject(fn ($rows, $key) => $key === '')
            ->map(fn (Collection $rows, string $key) => ['key' => '…'.substr($key, -4), 'user_ids' => $rows->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all()])
            ->filter(fn ($group) => count($group['user_ids']) > 1)
            ->values();
    }

    /** The last 10 digits, so 0801…, +234801… and 234801… all match. */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }

    /** "Ada (ada99)" for Fraud watch. */
    public static function nameOf(int $userId): string
    {
        $user = WpUser::query()->find($userId);

        return $user ? ($user->display_name ?: $user->user_login) : "Customer #{$userId}";
    }
}
