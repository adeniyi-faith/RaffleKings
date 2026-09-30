<?php

namespace App\Services;

use App\Filament\Pages\GamingTax;
use App\Models\GamingTaxReminder;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Notifications\GamingTaxReminder as ReminderNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Reminders that a month's gaming tax needs attention.
 *
 * For every month that has ended, has ticket sales or prizes, and is not paid
 * yet, staff who handle payouts are told:
 *   - on the first day after the month ends (open months only): lock it
 *   - a set number of days before the due date (7, 3 and 1 by default)
 *   - on the due date
 *   - once it is overdue: after 1, 3 and 7 days, then every 7 days
 * Each reminder is sent once, by email and to the staff Telegram chat (when
 * that is set up). The same information feeds the badge on the menu and the
 * notice at the top of the Gaming tax page. Nothing is sent before 9am
 * business time. Switch it off in Settings → Payments → Gaming tax.
 */
class GamingTaxReminders
{

    /** 1st, 2nd, 3rd, 21st... Plain PHP, because the server has no "intl" extension. */
    public static function ordinal(int $n): string
    {
        $suffix = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$n % 10]);

        return $n.$suffix;
    }

    /** How far back to look for months that still need attention. */
    private const MONTHS_BACK = 6;

    private const OVERDUE_DAYS = [1, 3, 7];

    private const SEND_FROM_HOUR = 9;

    private const BADGE_CACHE_KEY = 'gaming-tax-attention';

    public function __construct(private readonly GamingTaxService $tax) {}

    public function enabled(): bool
    {
        return (bool) config('gaming_tax.remind', true);
    }

    /** @return list<int> */
    public function daysBefore(): array
    {
        return collect((array) config('gaming_tax.remind_days', [7, 3, 1]))
            ->map(fn ($d) => (int) $d)->filter(fn ($d) => $d > 0 && $d <= 60)->unique()->sortDesc()->values()->all();
    }

    private function today(): Carbon
    {
        return now($this->tax->timezone())->startOfDay();
    }

    /**
     * Every ended, unpaid month with activity, and what it needs next.
     *
     * @return list<array{period: string, month: string, status: string, tax_due: float, due_on: string, days_to_due: int, severity: string, next_step: string, headline: string}>
     */
    public function attention(): array
    {
        $today = $this->today();
        $out = [];

        foreach (array_slice($this->tax->months(self::MONTHS_BACK + 1), 0, self::MONTHS_BACK + 1) as $period) {
            $s = $this->tax->statement($period);

            if ($s['status'] === 'paid' || ! $s['ended'] || ! ($s['sales'] > 0 || $s['prizes'] > 0 || $s['record'])) {
                continue;
            }

            $due = Carbon::parse($s['due_on'], $this->tax->timezone())->startOfDay();
            $days = (int) $today->diffInDays($due, false);
            $month = Carbon::createFromFormat('!Y-m', $period, $this->tax->timezone())->format('F Y');
            $step = match ($s['status']) {
                'open' => 'Lock the month, then file and pay.',
                'locked' => 'File the return, then pay.',
                default => 'Record the payment.',
            };

            $out[] = [
                'period' => $period,
                'month' => $month,
                'status' => $s['status'],
                'tax_due' => $s['tax_due'],
                'due_on' => $s['due_on'],
                'days_to_due' => $days,
                'severity' => $days < 0 ? 'overdue' : ($days <= max($this->daysBefore() ?: [7]) ? 'soon' : 'info'),
                'next_step' => $step,
                'headline' => match (true) {
                    $days < 0 => "{$month}: overdue by ".abs($days).' day'.(abs($days) === 1 ? '' : 's'),
                    $days === 0 => "{$month}: due today",
                    default => "{$month}: due in {$days} day".($days === 1 ? '' : 's'),
                },
            ];
        }

        return $out;
    }

    /** The count and colour for the menu badge, remembered for a few minutes so no page load recalculates months. @return array{count: int, danger: bool} */
    public function badge(): array
    {
        return Cache::remember(self::BADGE_CACHE_KEY, 600, function () {
            $needs = array_filter($this->attention(), fn ($a) => $a['severity'] !== 'info');

            return ['count' => count($needs), 'danger' => (bool) array_filter($needs, fn ($a) => $a['severity'] === 'overdue')];
        });
    }

    /** Forget the remembered badge (called whenever a month is locked, filed, paid or reopened). */
    public static function forgetBadge(): void
    {
        Cache::forget(self::BADGE_CACHE_KEY);
    }

    /**
     * The reminder each month should get today, if any (whether or not it
     * has been sent already).
     *
     * @return list<array{period: string, kind: string, attention: array}>
     */
    public function dueToday(): array
    {
        $out = [];

        foreach ($this->attention() as $a) {
            $days = $a['days_to_due'];
            $sinceEnd = (int) Carbon::createFromFormat('!Y-m', $a['period'], $this->tax->timezone())->addMonthNoOverflow()->startOfDay()->diffInDays($this->today(), false);

            $kind = match (true) {
                $days < 0 && (in_array(-$days, self::OVERDUE_DAYS, true) || (-$days) % 7 === 0) => 'overdue_'.(-$days),
                $days === 0 => 'due_today',
                $days > 0 && in_array($days, $this->daysBefore(), true) => 'before_'.$days,
                $a['status'] === 'open' && $sinceEnd === 0 => 'lock',
                default => null,
            };

            if ($kind) {
                $out[] = ['period' => $a['period'], 'kind' => $kind, 'attention' => $a];
            }
        }

        return $out;
    }

    /** Sends every reminder that is due and not sent yet. Returns how many months were reminded. */
    public function sendDue(): int
    {
        if (! $this->enabled() || now($this->tax->timezone())->hour < self::SEND_FROM_HOUR) {
            return 0;
        }

        $sent = 0;

        foreach ($this->dueToday() as $reminder) {
            if (GamingTaxReminder::query()->where('period', $reminder['period'])->where('kind', $reminder['kind'])->exists()) {
                continue;
            }

            $sent += $this->deliver($reminder['period'], $reminder['kind'], $reminder['attention']) ? 1 : 0;
        }

        return $sent;
    }

    /** @param  array<string, mixed>  $a */
    private function deliver(string $period, string $kind, array $a): bool
    {
        $due = Carbon::parse($a['due_on'], $this->tax->timezone())->format('j F Y');
        $tax = '₦'.number_format((float) $a['tax_due'], 2);
        $title = 'Gaming tax: '.$a['headline'];
        $body = "The gaming tax for {$a['month']} is {$tax}, due {$due}. {$a['next_step']}";

        if ($a['status'] === 'open') {
            $body .= ' (This is worked out so far. It is fixed when you lock the month.)';
        }

        $url = GamingTax::getUrl(['month' => $period]);
        $people = $this->recipients();

        // Once per (month, reminder), even if nobody could be emailed, so a missing address is not retried every hour.
        $row = GamingTaxReminder::query()->firstOrCreate(['period' => $period, 'kind' => $kind], ['sent_to' => $people->count(), 'sent_at' => now()]);

        if (! $row->wasRecentlyCreated) {
            return false;
        }

        if ($people->isNotEmpty()) {
            Notification::send($people, new ReminderNotification('mail', $title, $body, $url));
        }

        Notification::send(new AnonymousNotifiable, new ReminderNotification('telegram', $title, $body, $url));

        return true;
    }

    /** Staff who can pay out (owners, managers, finance) and have an email address. @return \Illuminate\Support\Collection<int, WpUser> */
    public function recipients()
    {
        $roleIds = WpUserMeta::query()->where('meta_key', \App\Auth\StaffRoles::META_KEY)->where('meta_value', '!=', \App\Auth\StaffRoles::NO_ACCESS)->pluck('user_id');
        $adminIds = WpUserMeta::query()->where('meta_key', config('legacy.wp_prefix').'capabilities')->where('meta_value', 'like', '%"administrator";b:1%')->pluck('user_id');

        return WpUser::query()->whereIn('ID', $roleIds->merge($adminIds)->unique())->get()
            ->filter(fn (WpUser $u) => $u->staffCan('money.pay') && filter_var($u->user_email, FILTER_VALIDATE_EMAIL))
            ->values();
    }
}
