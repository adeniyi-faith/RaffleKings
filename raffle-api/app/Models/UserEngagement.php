<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One customer's free perks and profile extras (Phase 11): free spins,
 * free bonus-entry tokens (added to their next ticket purchase), the
 * badges they pinned to their profile, and their birthday.
 */
class UserEngagement extends Model
{
    protected $table = 'user_engagement';

    protected $fillable = ['user_id', 'free_spins', 'bonus_entry_tokens', 'showcase', 'birthday', 'birthday_spin_year', 'milestones'];

    protected $casts = [
        'user_id' => 'integer',
        'free_spins' => 'integer',
        'bonus_entry_tokens' => 'integer',
        'showcase' => 'array',
        'milestones' => 'array',
    ];

    /** This customer's row, created on first use. */
    public static function for(int $userId): self
    {
        return static::query()->firstOrCreate(['user_id' => $userId]);
    }

    /** Locked for an update inside a transaction. */
    public static function lockFor(int $userId): self
    {
        static::for($userId);

        return static::query()->where('user_id', $userId)->lockForUpdate()->first();
    }
}
