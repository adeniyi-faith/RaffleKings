<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TutorialReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TutorialController extends Controller
{
    public function __construct(private readonly TutorialReadService $tutorials) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->tutorials->list(self::voter($request, required: false)));
    }

    public function show(Request $request, int $tutorial): JsonResponse
    {
        $found = $this->tutorials->find($tutorial, self::voter($request, required: false));

        return $found ? response()->json($found) : response()->json(['message' => 'Tutorial not found.'], 404);
    }

    /** POST = heart it, DELETE = take the heart back. */
    public function markHelpful(Request $request, int $tutorial): JsonResponse
    {
        $liked = ! $request->isMethod('delete');
        $newCount = $this->tutorials->like($tutorial, self::voter($request), $liked);

        if ($newCount === null) {
            return response()->json(['message' => 'Tutorial not found.'], 404);
        }

        return response()->json(['new_count' => $newCount, 'liked' => $liked]);
    }

    /**
     * Who is hearting: the signed-in customer, else the random id the
     * browser keeps for this device (sent as "device"), else a hash of
     * the IP address.
     */
    public static function voter(Request $request, bool $required = true): ?string
    {
        if ($user = Auth::guard('wordpress')->user()) {
            return 'u:'.$user->getAuthIdentifier();
        }

        $device = (string) $request->input('device', '');
        if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $device)) {
            return 'd:'.$device;
        }

        return $required ? 'ip:'.substr(sha1((string) $request->ip()), 0, 40) : null;
    }
}
