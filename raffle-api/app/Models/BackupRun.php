<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One nightly backup, and whether a practice restore of it worked. */
class BackupRun extends Model
{
    protected $fillable = ['file', 'size_bytes', 'tables', 'rows', 'row_counts', 'status', 'message', 'sent_offsite', 'restore_tested_at', 'restore_ok', 'restore_message'];

    protected $casts = [
        'row_counts' => 'array',
        'sent_offsite' => 'boolean',
        'restore_ok' => 'boolean',
        'restore_tested_at' => 'datetime',
    ];
}
