<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Services\Engagement\BadgeService;
use App\Services\Engagement\PlayerProfiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase 11 badges: the customer's collection, and pinning badges to their profile. */
class BadgeController extends Controller
{
    public function __construct(private readonly BadgeService $badges, private readonly PlayerProfiles $profiles) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->ID;

        return response()->json([
            'badges' => $this->badges->forUser($userId),
            'showcase_size' => $this->badges->showcaseSize(),
            'showcase' => $this->badges->showcase($userId),
            'privacy' => $this->privacy($request->user()),
        ]);
    }

    /** Who may see this customer's public profile card, and whether their wins show on it. */
    public function updatePrivacy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visibility' => ['sometimes', 'in:'.implode(',', PlayerProfiles::VISIBILITIES)],
            'show_wins' => ['sometimes', 'boolean'],
        ]);

        $this->profiles->update($request->user()->ID, $data);

        return response()->json(['privacy' => $this->privacy($request->user())]);
    }

    /** @return array{visibility: string, show_wins: bool, username: string, url: string, path: string} */
    private function privacy($user): array
    {
        return $this->profiles->settings($user->ID) + ['username' => $user->user_login, 'url' => url('/player/'.rawurlencode($user->user_login)), 'path' => '/player/'.rawurlencode($user->user_login)];
    }

    public function showcase(Request $request): JsonResponse
    {
        $data = $request->validate(['badges' => ['present', 'array', 'max:10'], 'badges.*' => ['string', 'max:40']]);

        return response()->json(['showcase' => $this->badges->setShowcase($request->user()->ID, $data['badges'])]);
    }
}
