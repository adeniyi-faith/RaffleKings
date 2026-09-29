<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** A winner's photo or video with their prize (Phase 11). Shown once staff approve it. */
class WinnerStory extends Model
{
    protected $fillable = ['user_id', 'raffle_winner_id', 'raffle_id', 'caption', 'media_path', 'media_type', 'status', 'review_note', 'approved_at'];

    protected $casts = ['user_id' => 'integer', 'raffle_id' => 'integer', 'approved_at' => 'datetime'];

    public function reactions(): HasMany
    {
        return $this->hasMany(WinnerStoryReaction::class);
    }

    public function mediaUrl(): string
    {
        return Storage::disk('public')->url($this->media_path);
    }
}
