<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One piece of platform know-how the AI support agent may answer from. */
class KnowledgeArticle extends Model
{
    protected $fillable = ['title', 'body', 'source_file', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
