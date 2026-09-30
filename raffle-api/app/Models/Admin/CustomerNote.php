<?php

namespace App\Models\Admin;

use App\Models\Legacy\WpUser;
use Illuminate\Database\Eloquent\Model;

/** A staff note on a customer ("called on 3 Oct about a late payout"). Staff only. */
class CustomerNote extends Model
{
    protected $fillable = ['user_id', 'author_id', 'body', 'pinned'];

    protected $casts = ['pinned' => 'boolean'];

    public function author()
    {
        return $this->belongsTo(WpUser::class, 'author_id', 'ID');
    }
}
