<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Models\SupportTicket;
use App\Services\AdminAuditLogService;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SupportTicketManagementController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets,
        private readonly AdminAuditLogService $auditLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->orderByDesc('created_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return response()->json(['tickets' => $query->paginate(50)]);
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        return response()->json($ticket->load('messages'));
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        $request->validate(['message' => ['required', 'string', 'max:5000']]);

        $reply = $this->tickets->reply($ticket, $admin, $request->string('message')->toString(), isFromAdmin: true);

        $this->auditLog->record($admin, 'support_ticket.replied', SupportTicket::class, $ticket->id, [
            'ticket_user_id' => $ticket->user_id,
        ]);

        return response()->json($reply, 201);
    }

    public function setStatus(Request $request, SupportTicket $ticket): JsonResponse
    {
        /** @var WpUser $admin */
        $admin = $request->user();

        $request->validate(['status' => ['required', 'string', 'in:open,pending,resolved,closed']]);

        try {
            $ticket = $this->tickets->setStatus($ticket, $request->string('status')->toString());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->auditLog->record($admin, 'support_ticket.status_changed', SupportTicket::class, $ticket->id, [
            'status' => $ticket->status,
        ]);

        return response()->json($ticket);
    }
}
