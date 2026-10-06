<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One item on the team to-do list (App\Services\Admin\StaffTodo). */
class StaffTask extends Model
{
    protected $fillable = [
        'source', 'source_key', 'title', 'detail', 'url', 'waiting_since', 'created_by',
        'done_at', 'done_by', 'done_by_name', 'done_note', 'cleared_at',
    ];

    protected $casts = [
        'waiting_since' => 'datetime',
        'done_at' => 'datetime',
        'cleared_at' => 'datetime',
        'done_by' => 'integer',
        'created_by' => 'integer',
    ];

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('done_at');
    }

    public function scopeDone(Builder $q): Builder
    {
        return $q->whereNotNull('done_at');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /** "Faith" / "Sorted in its queue" — who closed it, for the list. */
    public function doneByLabel(): ?string
    {
        if (! $this->isDone()) {
            return null;
        }

        return $this->done_by ? ($this->done_by_name ?: 'A staff member') : 'Sorted in its queue';
    }
}
