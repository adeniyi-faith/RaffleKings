<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Raffle advisor report: the totals it was shown, its summary and its
 * recommendations. A recommendation that was opened as a draft raffle keeps
 * that raffle's id in `opened_raffle_id`.
 */
class AdvisorReport extends Model
{
    protected $table = 'raffle_advisor_reports';

    protected $fillable = ['status', 'trigger', 'requested_by', 'focus', 'snapshot', 'summary', 'recommendations', 'model', 'error'];

    protected $casts = [
        'snapshot' => 'array',
        'recommendations' => 'array',
    ];

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
