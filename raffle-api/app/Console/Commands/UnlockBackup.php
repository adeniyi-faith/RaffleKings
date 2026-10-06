<?php

namespace App\Console\Commands;

use App\Services\Monitoring\BackupCrypto;
use Illuminate\Console\Command;
use Throwable;

class UnlockBackup extends Command
{
    protected $signature = 'backup:unlock {file : the locked backup (.sql.gz.enc)} {--out= : where to write the unlocked file}';

    protected $description = 'Unlocks (decrypts) a locked database backup so it can be restored';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $out = $this->option('out') ?: preg_replace('/\.enc$/', '', $file);

        if ($out === $file) {
            $this->error('Give --out, the file to write.');

            return self::FAILURE;
        }

        try {
            BackupCrypto::decrypt($file, $out);
        } catch (Throwable $e) {
            @unlink($out);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Unlocked to {$out}");

        return self::SUCCESS;
    }
}
