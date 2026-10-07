<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * A money record can only move along the routes its model lists in
 * STATUS_FLOW. Anything else (a paid withdrawal going back to waiting, a
 * credited top-up becoming failed) is refused and written to the log, so a
 * bug or a mistaken click can't quietly undo money that already moved.
 */
trait ChecksStatusFlow
{
    protected static function bootChecksStatusFlow(): void
    {
        static::updating(function ($model) {
            if (! $model->isDirty('status')) {
                return;
            }

            $from = (string) $model->getOriginal('status');
            $to = (string) $model->status;
            $allowed = static::STATUS_FLOW[$from] ?? [];

            if (! in_array($to, $allowed, true)) {
                \Illuminate\Support\Facades\Log::warning(class_basename($model)." #{$model->getKey()}: refused status change {$from} → {$to}.");

                throw new LogicException("A {$from} ".strtolower(class_basename($model))." can't become {$to}.");
            }
        });
    }
}
