<?php

namespace App\Jobs;

use App\Services\Retention\MemberSegments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** "Refresh now" on Growth → Member segments: sorts every customer again in the background. */
class RefreshMemberSegments implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function handle(MemberSegments $segments): void
    {
        $segments->refresh();
    }
}
