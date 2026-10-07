<?php

namespace App\Services\Compliance;

use App\Models\AccountRestriction;
use App\Models\BankAccount;
use App\Models\CustomerRiskLevel;
use App\Models\Legacy\WpUser;
use App\Services\Risk\FraudWatchService;
use Illuminate\Support\Facades\DB;

/**
 * A stored low / medium / high level for each customer with the reasons, so
 * "why is this person flagged" has an answer on the day, not a guess later.
 * It only points staff at who to look at first; it never blocks anyone.
 */
class CustomerRiskScore
{
    private const POINTS = [
        'shared_bank' => 2,
        'shared_phone' => 2,
        'shared_device' => 2,
        'quick_cashout' => 2,
        'rapid_topups' => 1,
    ];

    public function __construct(private readonly FraudWatchService $fraud) {}

    /** @return array{level: string, score: int, reasons: list<string>} */
    public function calculate(WpUser $user): array
    {
        $score = 0;
        $reasons = [];

        foreach ($this->fraud->flagsFor($user) as $flag) {
            $score += self::POINTS[$flag['type']] ?? 1;
            $reasons[] = $flag['text'];
        }

        if (BankAccount::query()->active()->where('user_id', $user->ID)->where('name_mismatch', true)->exists()) {
            $score += 2;
            $reasons[] = 'A saved bank account is in a name that does not look like theirs.';
        }

        $restrictions = AccountRestriction::query()->active()->where('user_id', $user->ID)->count();

        if ($restrictions > 0) {
            $score += 1;
            $reasons[] = 'Has an active restriction or ban.';
        }

        return ['level' => $score >= 3 ? 'high' : ($score >= 1 ? 'medium' : 'low'), 'score' => $score, 'reasons' => $reasons];
    }

    public function refresh(WpUser $user): CustomerRiskLevel
    {
        $result = $this->calculate($user);

        return CustomerRiskLevel::updateOrCreate(['user_id' => $user->ID], $result + ['calculated_at' => now()]);
    }

    /** Everyone who did anything involving money in the last 90 days. */
    public function refreshActive(): int
    {
        $ids = DB::table('wallet_ledger_entries')->where('created_at', '>=', now()->subDays(90))->distinct()->pluck('user_id')
            ->merge(AccountRestriction::query()->active()->pluck('user_id'))
            ->merge(CustomerRiskLevel::query()->where('level', '!=', 'low')->pluck('user_id'))
            ->unique();

        $count = 0;

        foreach ($ids->chunk(200) as $chunk) {
            WpUser::query()->whereIn('ID', $chunk)->get()->each(function (WpUser $user) use (&$count) {
                $this->refresh($user);
                $count++;
            });
        }

        return $count;
    }
}
