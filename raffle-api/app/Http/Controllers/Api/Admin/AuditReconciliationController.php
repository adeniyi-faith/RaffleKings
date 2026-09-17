<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReconcileAuditRequest;
use App\Services\AuditReconciliationService;
use Illuminate\Http\JsonResponse;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the new console's own version
 * of legacy's Daily Audit page's reconciliation step. See
 * AuditReconciliationService's docblock: this takes already-parsed bank
 * credits (the part a bank-statement upload + AI OCR step would
 * normally produce — deliberately not built in this pass) and returns
 * which "verified" transactions in the period don't have a matching
 * credit. TransactionMonitorController::revoke() is the action an admin
 * takes on whatever comes back flagged.
 */
class AuditReconciliationController extends Controller
{
    public function __construct(private readonly AuditReconciliationService $reconciliation) {}

    public function store(ReconcileAuditRequest $request): JsonResponse
    {
        $flagged = $this->reconciliation->reconcile(
            $request->array('credits'),
            $request->string('start_date')->toString(),
            $request->string('end_date')->toString(),
        );

        return response()->json(['flagged' => $flagged->values()]);
    }
}
