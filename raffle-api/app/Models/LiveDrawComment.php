<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveDrawComment extends Model
{
    protected $fillable = ['raffle_id', 'user_id', 'body'];

    public function raffle(): BelongsTo
    {
        return $this->belongsTo(Raffle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    /** What the frontend and the broadcast payload both actually need — never the raw model. */
    public function toBroadcastArray(): array
    {
        return [
            'id' => $this->id,
            'raffle_id' => $this->raffle_id,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->display_name ?: $this->user?->user_login,
            'body' => $this->body,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
