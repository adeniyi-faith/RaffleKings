<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Written once. The database refuses to change or delete it. */
class ComplianceCaseNote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['case_id', 'author_id', 'body'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Case notes cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Case notes cannot be deleted.'));
    }
}
