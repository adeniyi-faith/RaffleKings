<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A daily prediction question (Phase 11): free to answer, points for a
 * right answer once staff settle it. Questions the AI writes start as
 * drafts (is_draft) that customers can't see until staff publish them;
 * source_url, ai_note and suggested_option are for staff only.
 */
class Prediction extends Model
{
    public const CATEGORIES = ['football' => '⚽ Football', 'quiz' => '🧠 Quiz', 'raffle' => '🎟️ Raffle', 'other' => '✨ Other'];

    protected $fillable = ['category', 'question', 'options', 'correct_option', 'points', 'opens_at', 'closes_at', 'settled_at',
        'is_draft', 'source_url', 'ai_note', 'suggested_option'];

    protected $casts = [
        'options' => 'array',
        'correct_option' => 'integer',
        'points' => 'integer',
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'settled_at' => 'datetime',
        'is_draft' => 'boolean',
        'suggested_option' => 'integer',
    ];

    public function answers(): HasMany
    {
        return $this->hasMany(PredictionAnswer::class);
    }

    public function isOpen(): bool
    {
        return ! $this->is_draft && ! $this->settled_at && (! $this->opens_at || $this->opens_at->isPast()) && $this->closes_at->isFuture();
    }
}
