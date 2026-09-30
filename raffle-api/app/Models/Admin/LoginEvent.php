<?php

namespace App\Models\Admin;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/** One sign-in attempt, on the site or the admin (successful or not). */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'identifier', 'success', 'place', 'reason', 'ip', 'device'];

    protected $casts = ['success' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    /** Never breaks a sign-in. */
    public static function record(?int $userId, ?string $identifier, bool $success, string $place, ?string $reason = null, ?Request $request = null): void
    {
        try {
            $request ??= request();
            static::create([
                'user_id' => $userId,
                'identifier' => $identifier !== null ? mb_substr($identifier, 0, 100) : null,
                'success' => $success,
                'place' => $place,
                'reason' => $reason,
                'ip' => $request?->ip(),
                'device' => $request?->userAgent() ? mb_substr((string) $request->userAgent(), 0, 200) : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** "Chrome on Android" from the browser's description. */
    public function deviceName(): string
    {
        $ua = (string) $this->device;
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'A browser',
        };
        $system = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iPhone/iPad',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown device',
        };

        return "{$browser} on {$system}";
    }
}
