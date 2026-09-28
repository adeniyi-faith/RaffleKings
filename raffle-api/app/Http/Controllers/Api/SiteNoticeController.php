<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\RaffleSiteNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * OVERHAUL_CHECKLIST.md item 45 — the live announcements every page shows
 * (same data the old site's header.php fetched). Public, and cached for a
 * minute so every page load doesn't hit the database; an admin's change
 * clears the cache straight away (SiteNoticeResource).
 */
class SiteNoticeController extends Controller
{
    public const CACHE_KEY = 'site-notices:live';

    public function index(): JsonResponse
    {
        $notices = Cache::remember(self::CACHE_KEY, 60, fn () => RaffleSiteNotice::query()
            ->live()
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (RaffleSiteNotice $n) => $n->toPublicArray())
            ->all());

        return response()->json(['notices' => $notices]);
    }
}
