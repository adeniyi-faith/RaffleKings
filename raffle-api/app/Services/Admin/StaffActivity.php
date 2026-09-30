<?php

namespace App\Services\Admin;

use App\Filament\Resources\StaffResource;
use App\Models\Admin\LoginEvent;
use App\Models\AdminAuditLog;
use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Users → Staff activity: who signed in to the admin, from where, who has
 * been guessing passwords, and what each person changed. Built from the
 * sign-in record (LoginEvent) and the audit log (AdminAuditLogService).
 */
final class StaffActivity
{
    /** Days the per-person numbers cover. */
    public const DAYS = 30;

    /** Failed admin sign-ins in a day before it is called out. */
    public const FAILED_ALERT_AT = 5;

    /** Every staff member, with their recent numbers, ready for a table. */
    public function people(): Builder
    {
        $users = (new WpUser)->getTable();
        $since = now()->subDays(self::DAYS);
        $signIns = fn () => LoginEvent::query()->whereColumn('user_id', "{$users}.ID")->where('place', 'admin');
        $actions = fn () => AdminAuditLog::query()->whereColumn('admin_user_id', "{$users}.ID")->where('action', '!=', 'staff.signed_in');

        return StaffResource::getEloquentQuery()->addSelect([
            'last_sign_in_at' => $signIns()->where('success', true)->select('created_at')->orderByDesc('created_at')->limit(1),
            'sign_ins_30' => $signIns()->where('success', true)->where('created_at', '>=', $since)->selectRaw('count(*)'),
            'failed_30' => $signIns()->where('success', false)->where('created_at', '>=', $since)->selectRaw('count(*)'),
            'actions_30' => $actions()->where('created_at', '>=', $since)->selectRaw('count(*)'),
            'last_action_at' => $actions()->select('created_at')->orderByDesc('created_at')->limit(1),
        ]);
    }

    /**
     * Things worth a look, newest concerns first.
     *
     * @return array{failed: array{count: int, places: int, who: list<string>}|null, new_places: list<array{name: string, when: \Illuminate\Support\Carbon, ip: ?string, device: string}>}
     */
    public function alerts(): array
    {
        $failed = LoginEvent::query()->where('place', 'admin')->where('success', false)->where('created_at', '>=', now()->subDay())->get();

        return [
            'failed' => $failed->count() >= self::FAILED_ALERT_AT ? [
                'count' => $failed->count(),
                'places' => $failed->pluck('ip')->filter()->unique()->count(),
                'who' => $failed->pluck('identifier')->filter()->countBy()->sortDesc()->keys()->take(3)->all(),
            ] : null,
            'new_places' => $this->newPlaces(now()->subDays(7))->all(),
        ];
    }

    /**
     * Successful sign-ins since $from that came from a place (IP address)
     * that person had never signed in from before. Someone's very first
     * sign-in isn't flagged, only later ones.
     *
     * @return Collection<int, array{name: string, when: \Illuminate\Support\Carbon, ip: ?string, device: string}>
     */
    public function newPlaces(\Illuminate\Support\Carbon $from): Collection
    {
        $staffIds = StaffResource::getEloquentQuery()->pluck((new WpUser)->getKeyName());
        $names = WpUser::query()->whereIn('ID', $staffIds)->get()->keyBy('ID');
        $seen = [];
        $found = collect();

        LoginEvent::query()->where('place', 'admin')->where('success', true)->whereIn('user_id', $staffIds)
            ->orderBy('created_at')->orderBy('id')->get()->each(function (LoginEvent $e) use (&$seen, $from, $names, $found) {
                $known = $seen[$e->user_id] ?? null;

                if ($known !== null && ! in_array($e->ip, $known, true) && $e->created_at >= $from) {
                    $found->push(['name' => $names[$e->user_id]?->display_name ?: $names[$e->user_id]?->user_login ?: "#{$e->user_id}", 'when' => $e->created_at, 'ip' => $e->ip, 'device' => $e->deviceName()]);
                }

                $seen[$e->user_id][] = $e->ip;
            });

        return $found->sortByDesc('when')->values();
    }

    /**
     * One person's recent admin sign-in attempts, newest first, each marked
     * when it came from a new place.
     *
     * @return list<array{when: \Illuminate\Support\Carbon, ok: bool, why: ?string, ip: ?string, device: string, new_place: bool}>
     */
    public function signIns(WpUser $user, int $limit = 40): array
    {
        $seen = [];
        $rows = [];

        LoginEvent::query()->where('place', 'admin')->where('user_id', $user->ID)->orderBy('created_at')->orderBy('id')->get()->each(function (LoginEvent $e) use (&$seen, &$rows) {
            $rows[] = [
                'when' => $e->created_at,
                'ok' => $e->success,
                'why' => $e->success ? null : match ($e->reason) {
                    'wrong_password' => 'Wrong password',
                    'banned' => 'Account suspended',
                    'not_staff' => 'Not a staff account',
                    'two_step_failed' => 'Wrong or expired emailed code',
                    default => 'Refused',
                },
                'ip' => $e->ip,
                'device' => $e->deviceName(),
                'new_place' => $e->success && $seen !== [] && ! in_array($e->ip, $seen, true),
            ];

            if ($e->success) {
                $seen[] = $e->ip;
            }
        });

        return array_slice(array_reverse($rows), 0, $limit);
    }

    /** What one person changed lately, newest first. @return Collection<int, AdminAuditLog> */
    public function actions(WpUser $user, int $limit = 50): Collection
    {
        return AdminAuditLog::query()->where('admin_user_id', $user->ID)->where('action', '!=', 'staff.signed_in')
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }
}
