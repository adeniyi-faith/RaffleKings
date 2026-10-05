<?php

namespace App\Console\Commands;

use App\Services\Guides\GuideSync;
use Illuminate\Console\Command;

class SyncGuides extends Command
{
    protected $signature = 'guides:sync {--refresh : Rewrite guides that are already there (this replaces any edits staff made to them)}';

    protected $description = 'Add the built-in help guides to the Learning Hub and the Knowledge base';

    public function handle(GuideSync $sync): int
    {
        $stats = $sync->run((bool) $this->option('refresh'));

        $this->info("Guides added: {$stats['created']}, rewritten: {$stats['updated']}, left as they were: {$stats['skipped']}.");

        return self::SUCCESS;
    }
}
