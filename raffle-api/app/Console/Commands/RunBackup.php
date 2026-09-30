<?php

namespace App\Console\Commands;

use App\Services\Monitoring\DatabaseBackup;
use Illuminate\Console\Command;

/** Nightly from the scheduler; can also be run by hand. */
class RunBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Copy the whole database to a compressed file in storage/app/backups';

    public function handle(DatabaseBackup $backups): int
    {
        $run = $backups->run();

        $run->status === 'ok' ? $this->info($run->message.' File: '.$run->file) : $this->error($run->message);

        return $run->status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
