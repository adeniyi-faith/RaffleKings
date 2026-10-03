<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OpenSupportTicketRequest;
use App\Models\Legacy\WpUser;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        return response()->json([
            'tickets' => SupportTicket::query()->where('user_id', $user->ID)->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(OpenSupportTicketRequest $request): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        $ticket = $this->tickets->open(
            $user,
            $request->string('subject')->toString(),
            $request->string('message')->toString(),
            $request->file('screenshots', []),
        );

        return response()->json($ticket->load('messages'), 201);
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        if ($ticket->user_id !== $user->ID) {
            return response()->json(['message' => 'Ticket not found.'], 404);
        }

        return response()->json($ticket->load('messages'));
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        if ($ticket->user_id !== $user->ID) {
            return response()->json(['message' => 'Ticket not found.'], 404);
        }

        $request->validate([
            'message' => ['required_without:screenshots', 'nullable', 'string', 'max:5000'],
            'screenshots' => ['nullable', 'array', 'max:'.OpenSupportTicketRequest::MAX_SCREENSHOTS],
            'screenshots.*' => OpenSupportTicketRequest::SCREENSHOT_RULES,
        ]);

        $reply = $this->tickets->reply($ticket, $user, $request->string('message')->toString(), isFromAdmin: false, screenshots: $request->file('screenshots', []));

        return response()->json($reply, 201);
    }

    /** "Talk to a person": stops the automated replies and puts the ticket back in the team's open inbox. */
    public function requestHuman(Request $request, SupportTicket $ticket): JsonResponse
    {
        /** @var WpUser $user */
        $user = $request->user();

        if ($ticket->user_id !== $user->ID) {
            return response()->json(['message' => 'Ticket not found.'], 404);
        }

        $this->tickets->requestHuman($ticket);

        return response()->json($ticket->refresh()->load('messages'));
    }
}
