<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\StatementExtractionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExtractStatementRequest;
use App\Services\StatementExtractionService;
use Illuminate\Http\JsonResponse;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — turns an uploaded bank
 * statement into the `credits` array AuditReconciliationController's
 * own /audit/reconcile endpoint already expects. Deliberately a
 * separate step rather than one combined upload+reconcile call: the
 * admin gets to see and correct what the AI actually read (a missed or
 * misread line is exactly the kind of mistake a human should catch
 * before it's used to decide which real transactions get revoked)
 * before submitting the reconcile step.
 */
class StatementExtractionController extends Controller
{
    public function __construct(private readonly StatementExtractionService $extraction) {}

    public function store(ExtractStatementRequest $request): JsonResponse
    {
        try {
            $credits = $this->extraction->extractCredits($request->file('files'));
        } catch (StatementExtractionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['credits' => $credits]);
    }
}
