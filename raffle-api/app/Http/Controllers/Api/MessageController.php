<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's on-site inbox (the bell): messages staff sent from
 * Site → Message customers.
 */
class MessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->getKey();
        $messages = CustomerMessage::query()->where('user_id', $userId)->latest('id')->limit(50)->get();

        return response()->json([
            'messages' => $messages->map->toPublicArray()->values(),
            'unread' => CustomerMessage::query()->where('user_id', $userId)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, int $message): JsonResponse
    {
        CustomerMessage::query()->where('user_id', $request->user()->getKey())->whereKey($message)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        CustomerMessage::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
