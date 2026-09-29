<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One customer's answer to a daily prediction (Phase 11). */
class PredictionAnswer extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['prediction_id', 'user_id', 'option', 'is_correct', 'points_awarded'];

    protected $casts = ['option' => 'integer', 'is_correct' => 'boolean', 'points_awarded' => 'integer'];

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(Prediction::class);
    }
}
