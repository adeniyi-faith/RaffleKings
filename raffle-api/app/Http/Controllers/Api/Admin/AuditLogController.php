<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read access to the admin audit log (fixes audit TD-15 — "no admin
 * action audit log anywhere"). Every mutating admin action in this app
 * writes here via AdminAuditLogService; this just makes that record
 * visible.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AdminAuditLog::query()->orderByDesc('created_at');

        if ($subjectType = $request->string('subject_type')->toString()) {
            $query->where('subject_type', $subjectType);
        }

        if ($adminUserId = $request->integer('admin_user_id')) {
            $query->where('admin_user_id', $adminUserId);
        }

        return response()->json(['logs' => $query->paginate(50)]);
    }
}
