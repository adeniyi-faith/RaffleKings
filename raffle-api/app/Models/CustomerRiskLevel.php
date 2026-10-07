<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerRiskLevel extends Model
{
    protected $fillable = ['user_id', 'level', 'score', 'reasons', 'calculated_at'];

    protected $casts = ['reasons' => 'array', 'calculated_at' => 'datetime'];
}
