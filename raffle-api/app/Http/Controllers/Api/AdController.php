<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Services\Ads\AdServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * The on-site ads (Site → Ads): which ads to show in the spots on a page,
 * and counting views, taps and closes. Public, so visitors who are not
 * logged in see ads too.
 */
class AdController extends Controller
{
    public function __construct(private readonly AdServer $ads) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slots' => ['required', 'string', 'max:300'],
            'v' => ['nullable', 'string', 'max:64'],
            'path' => ['nullable', 'string', 'max:300'],
        ]);

        $ads = $this->ads->pick(
            array_slice(explode(',', $data['slots']), 0, 12),
            $this->user(),
            $data['v'] ?? null,
            $request->ip(),
            $data['path'] ?? null,
        );

        return response()->json([
            'ads' => (object) $ads,
            'popup_gap_hours' => (int) config('ads.popup_gap_hours', 12),
        ])->header('Cache-Control', 'no-store');
    }

    public function event(Request $request): Response
    {
        $data = $request->validate([
            't' => ['required', 'string', 'max:80'],
            'e' => ['required', 'string', 'in:view,click,close'],
            'v' => ['nullable', 'string', 'max:64'],
        ]);

        $this->ads->record($data['t'], $data['e'], $this->user(), $data['v'] ?? null, $request->ip());

        return response()->noContent();
    }

    private function user(): ?WpUser
    {
        $user = Auth::guard('wordpress')->user();

        return $user instanceof WpUser ? $user : null;
    }
}
