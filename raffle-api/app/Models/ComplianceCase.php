<?php

namespace App\Models;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

class ComplianceCase extends Model
{
    protected $fillable = ['user_id', 'title', 'details', 'status', 'opened_by', 'owner_id', 'closed_by', 'closed_at', 'outcome'];

    protected $casts = ['closed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(WpUser::class, 'user_id', 'ID');
    }

    public function notes()
    {
        return $this->hasMany(ComplianceCaseNote::class, 'case_id')->orderBy('id');
    }
}
