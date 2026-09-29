<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\ErrorAlerter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase 10 monitoring: the site's own pages report JavaScript errors here
 * (resources/js/lib/errorReporter.js), so a page that breaks on a
 * customer's phone shows on System → Health instead of going unseen.
 * Public (a logged-out visitor's page can break too) and rate-limited.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request, ErrorAlerter $alerter): Response
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:500'],
            'page' => ['nullable', 'string', 'max:500'],
        ]);

        $alerter->browser($data['message'], $data['source'] ?? null, $data['page'] ?? null, $request->userAgent());

        return response()->noContent();
    }
}
