<?php

namespace App\Jobs;

use App\Models\AdvisorReport;
use App\Services\Advisor\RaffleAdvisor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes one Raffle advisor report in the background: Claude can take a
 * minute or two to think, longer than a web page should wait. Runs once;
 * if anything goes wrong the report is marked failed so staff can retry.
 */
class WriteAdvisorReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 540;

    public function __construct(public readonly int $reportId) {}

    public function handle(RaffleAdvisor $advisor): void
    {
        $report = AdvisorReport::query()->find($this->reportId);

        if ($report?->isPending()) {
            $advisor->write($report);
        }
    }

    public function failed(?\Throwable $e): void
    {
        AdvisorReport::query()->whereKey($this->reportId)->where('status', 'pending')
            ->update(['status' => 'failed', 'error' => 'Something went wrong while writing this report. Try again.']);
    }
}
