<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One locked month of gaming tax — see App\Services\GamingTaxService. */
class GamingTaxPeriod extends Model
{
    protected $fillable = [
        'period', 'sales', 'refunds', 'prizes', 'carried_in', 'taxable', 'carried_out', 'rate', 'shortfall_rule', 'tax_due', 'by_raffle',
        'status', 'locked_at', 'locked_by', 'filed_at', 'filed_by', 'filing_reference', 'paid_at', 'paid_by', 'paid_amount', 'payment_reference',
    ];

    protected $casts = [
        'sales' => 'float',
        'refunds' => 'float',
        'prizes' => 'float',
        'carried_in' => 'float',
        'taxable' => 'float',
        'carried_out' => 'float',
        'rate' => 'float',
        'tax_due' => 'float',
        'paid_amount' => 'float',
        'by_raffle' => 'array',
        'locked_at' => 'datetime',
        'filed_at' => 'datetime',
        'paid_at' => 'datetime',
    ];
}
