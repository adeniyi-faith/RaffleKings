<?php

namespace App\Console\Commands;

use App\Services\Monitoring\DatabaseBackup;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads the latest backup into the separate practice database and checks
 * every table's rows are all there. Never touches the live database.
 */
class TestRestoreBackup extends Command
{
    protected $signature = 'backup:test-restore';

    protected $description = 'Prove the latest backup restores, using the separate practice database';

    public function handle(DatabaseBackup $backups): int
    {
        try {
            $run = $backups->testRestore();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $run->restore_ok ? $this->info($run->restore_message) : $this->error($run->restore_message);

        return $run->restore_ok ? self::SUCCESS : self::FAILURE;
    }
}
