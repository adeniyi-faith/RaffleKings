<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Retention\ComebackOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Claiming a comeback offer (App\Services\Retention\ComebackOffers). */
class OfferController extends Controller
{
    public function claim(Request $request, string $token, ComebackOffers $offers): JsonResponse
    {
        try {
            $result = $offers->claim($token, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'redirect' => $result['redirect'],
        ]);
    }
}
