<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerMessage;
use App\Services\Retention\DeliveryTracker;
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
        $messages = CustomerMessage::query()->where('user_id', $userId)->latest('created_at')->latest('id')->limit(50)->get();

        return response()->json([
            'messages' => $messages->map->toPublicArray()->values(),
            'unread' => CustomerMessage::query()->where('user_id', $userId)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, int $message): JsonResponse
    {
        CustomerMessage::query()->where('user_id', $request->user()->getKey())->whereKey($message)->whereNull('read_at')->update(['read_at' => now()]);
        $this->track($request, $message, clicked: false);

        return response()->json(['ok' => true]);
    }

    /** They tapped the message's button (counted as a click on the site channel). */
    public function tapped(Request $request, int $message): JsonResponse
    {
        $this->track($request, $message, clicked: true);

        return response()->json(['ok' => true]);
    }

    private function track(Request $request, int $message, bool $clicked): void
    {
        $deliveryId = CustomerMessage::query()->where('user_id', $request->user()->getKey())->whereKey($message)->value('delivery_id');

        if ($deliveryId) {
            app(DeliveryTracker::class)->openedInbox((int) $deliveryId, $clicked);
        }
    }

    public function readAll(Request $request): JsonResponse
    {
        CustomerMessage::query()->where('user_id', $request->user()->getKey())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
