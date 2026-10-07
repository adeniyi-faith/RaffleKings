<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing the nightly money checks found that a person has to look at. */
class MoneyReviewItem extends Model
{
    protected $fillable = [
        'kind', 'reference', 'title', 'details', 'status', 'owner_id',
        'found_at', 'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = ['found_at' => 'datetime', 'resolved_at' => 'datetime'];

    /**
     * Raises (or refreshes) one item. The same problem found again on the next
     * night is the same item, not a new one; a resolved one that comes back opens again.
     */
    public static function raise(string $kind, string $reference, string $title, ?string $details = null): self
    {
        $item = static::query()->firstOrNew(['kind' => $kind, 'reference' => mb_substr($reference, 0, 120)]);
        $reopened = $item->exists && $item->status === 'resolved';

        $item->fill(['title' => mb_substr($title, 0, 200), 'details' => $details]);

        if (! $item->exists || $reopened) {
            $item->fill(['status' => 'open', 'found_at' => now(), 'resolved_at' => null, 'resolved_by' => null]);
        }

        // Seen again tonight: the check closes items it did NOT see this run.
        $item->updated_at = now();
        $item->save();

        return $item;
    }
}
