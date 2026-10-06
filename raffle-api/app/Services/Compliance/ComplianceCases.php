<?php

namespace App\Services\Compliance;

use App\Models\ComplianceCase;
use App\Models\ComplianceCaseNote;
use App\Models\Legacy\WpUser;
use App\Services\AdminAuditLogService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A simple case list for things that need looking into (a suspicious
 * customer, a large or odd money movement). open → investigating → closed.
 * Notes can only be added, never changed. Closing a case takes a different
 * staff member from the one who opened it.
 */
class ComplianceCases
{
    public function __construct(private readonly AdminAuditLogService $audit) {}

    public function open(WpUser $staff, WpUser $customer, string $title, ?string $details = null): ComplianceCase
    {
        $title = trim($title);

        if ($title === '') {
            throw new RuntimeException('Give the case a short title.');
        }

        $case = ComplianceCase::create([
            'user_id' => $customer->ID,
            'title' => mb_substr($title, 0, 200),
            'details' => $details ? mb_substr($details, 0, 4000) : null,
            'opened_by' => $staff->ID,
        ]);

        $this->audit->record($staff, 'compliance_case.opened', WpUser::class, $customer->ID, ['case_id' => $case->id, 'title' => $case->title]);

        return $case;
    }

    public function note(WpUser $staff, ComplianceCase $case, string $body): ComplianceCaseNote
    {
        if ($case->status === 'closed') {
            throw new RuntimeException('This case is closed.');
        }

        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Write the note first.');
        }

        return ComplianceCaseNote::create(['case_id' => $case->id, 'author_id' => $staff->ID, 'body' => mb_substr($body, 0, 4000)]);
    }

    public function take(WpUser $staff, ComplianceCase $case): ComplianceCase
    {
        if ($case->status === 'closed') {
            throw new RuntimeException('This case is closed.');
        }

        $case->update(['owner_id' => $staff->ID, 'status' => 'investigating']);
        $this->audit->record($staff, 'compliance_case.taken', WpUser::class, (int) $case->user_id, ['case_id' => $case->id]);

        return $case;
    }

    public function close(WpUser $staff, ComplianceCase $case, string $outcome): ComplianceCase
    {
        $outcome = trim($outcome);

        if ($outcome === '') {
            throw new RuntimeException('Say what was found and decided.');
        }

        DB::transaction(function () use ($staff, $case, $outcome) {
            $locked = ComplianceCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'closed') {
                throw new RuntimeException('This case is already closed.');
            }

            if ((int) $locked->opened_by === (int) $staff->ID) {
                throw new RuntimeException('A different staff member has to close a case you opened.');
            }

            if ((int) $locked->user_id === (int) $staff->ID) {
                throw new RuntimeException('You can\'t close a case about your own account.');
            }

            $locked->update(['status' => 'closed', 'closed_by' => $staff->ID, 'closed_at' => now(), 'outcome' => mb_substr($outcome, 0, 4000)]);
        });

        $this->audit->record($staff, 'compliance_case.closed', WpUser::class, (int) $case->user_id, ['case_id' => $case->id, 'outcome' => $outcome]);

        return $case->refresh();
    }
}
