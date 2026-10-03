<?php

namespace App\Http\Controllers;

use App\Models\SupportTicketMessage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shows one screenshot attached to a support message. Only reachable
 * through the short-lived signed link in SupportTicketMessage::attachment_urls,
 * which is only handed to the ticket's owner and to staff.
 */
class SupportAttachmentController extends Controller
{
    public function __invoke(SupportTicketMessage $message, int $index): StreamedResponse
    {
        $path = ($message->attachments ?? [])[$index] ?? null;
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
