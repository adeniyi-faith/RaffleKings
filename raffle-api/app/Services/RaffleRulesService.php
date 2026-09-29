<?php

namespace App\Services;

use App\Models\Legacy\RaffleEntry;
use App\Models\Raffle;
use App\Models\RaffleBonusEntry;
use App\Services\Draw\DrawRules;

/**
 * The customer-facing side of the Raffle Rules Engine: who may enter a
 * raffle (members-only / new-players-only rules) and the free loyalty
 * bonus entries a purchase earns. Everything here follows the rules
 * published on the raffle page — nothing is hidden.
 */
class RaffleRulesService
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /** The admin-managed raffle behind a public raffle number, if any. */
    public function raffle(int $publicId): ?Raffle
    {
        return Raffle::query()->where('public_id', $publicId)->first();
    }

    /**
     * Why this customer can't enter this raffle, in plain words, or null
     * when they can.
     */
    public function whyNotEligible(int $userId, int $publicId): ?string
    {
        $raffle = $this->raffle($publicId);

        if (! $raffle) {
            return null;
        }

        $rules = $raffle->drawRules();

        if ($rules->minTier && ! $this->loyalty->meets($userId, $rules->minTier)) {
            return 'This raffle is for '.ucfirst($rules->minTier).' loyalty members and above. Keep playing each week to move up — see your progress on the Rewards page.';
        }

        if ($rules->newPlayersOnly && RaffleEntry::query()->where('user_id', $userId)->where('raffle_id', '!=', $publicId)->exists()) {
            return 'This raffle is only for players buying their first ever ticket.';
        }

        return null;
    }

    /**
     * After tickets are added: if this raffle's rules give loyalty bonus
     * entries, make sure the customer holds their tier's free entries here
     * (never more than their tier gives, never taken away).
     */
    public function grantBonusEntries(int $userId, int $publicId): void
    {
        $raffle = $this->raffle($publicId);

        if (! $raffle || ! $raffle->drawRules()->loyaltyBonusEntries) {
            return;
        }

        $this->loyalty->forget($userId);
        $tier = $this->loyalty->profile($userId)['tier'];

        if ($tier['bonus_entries'] < 1) {
            return;
        }

        $row = RaffleBonusEntry::query()->firstOrNew(['raffle_id' => $publicId, 'user_id' => $userId]);

        if ($row->exists && $row->entries >= $tier['bonus_entries']) {
            return;
        }

        $row->fill(['entries' => $tier['bonus_entries'], 'reason' => 'loyalty', 'tier' => $tier['key']])->save();
    }

    /** The customer's free bonus entries in one raffle (0 if none). */
    public function bonusEntries(int $userId, int $publicId): int
    {
        return (int) RaffleBonusEntry::query()->where('raffle_id', $publicId)->where('user_id', $userId)->value('entries');
    }

    /**
     * What the raffle page shows: "How this draw works", and for a signed-in
     * customer whether they can enter and the bonus entries they hold or
     * would earn.
     *
     * @return array{rules: list<string>, not_eligible: ?string, bonus_entries: int, tier: ?array, tier_bonus: int}
     */
    public function forRafflePage(int $publicId, ?int $userId): array
    {
        $raffle = $this->raffle($publicId);
        $rules = $raffle?->drawRules() ?? DrawRules::legacy();
        $tier = $userId ? $this->loyalty->profile($userId)['tier'] : null;

        return [
            'rules' => $rules->describe($this->loyalty->tiers()),
            'not_eligible' => $userId ? $this->whyNotEligible($userId, $publicId) : null,
            'bonus_entries' => $userId ? $this->bonusEntries($userId, $publicId) : 0,
            'tier' => $tier ? ['key' => $tier['key'], 'name' => $tier['name']] : null,
            'tier_bonus' => $rules->loyaltyBonusEntries && $tier ? (int) $tier['bonus_entries'] : 0,
        ];
    }
}
