<?php

namespace App\Services\Monitoring;

use App\Models\BackupRun;
use App\Notifications\SystemProblemAdminAlert;
use Illuminate\Database\Connection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Nightly database backups that are PROVEN to restore.
 *
 *  1. run(): writes every table (structure and rows) to one compressed
 *     file in storage/app/backups, noting each table's row count. Written
 *     in plain PHP, so it works on shared cPanel hosting where the
 *     mysqldump program often can't be started. On MySQL the whole copy
 *     is taken from one consistent moment (a snapshot transaction), so a
 *     purchase happening mid-backup can't leave it half-recorded.
 *  2. testRestore(): loads that file into a SEPARATE practice database
 *     and checks every table has exactly the rows it had when copied.
 *     A backup nobody has restored is only a hope; this makes it a fact.
 *  3. Optionally sends the file to the staff Telegram chat, so a copy
 *     lives somewhere other than the server it protects.
 *
 * Any failure is recorded (System → Health) and sent to staff on Telegram.
 *
 * Every statement is written on ONE line (newlines inside values are
 * escaped), which is what lets the restore read it back line by line.
 */
class DatabaseBackup
{
    private const ROWS_PER_INSERT = 200;

    /** Telegram bots can send files up to 50 MB. */
    private const TELEGRAM_MAX_BYTES = 45 * 1024 * 1024;

    public function directory(): string
    {
        return storage_path('app/backups');
    }

    public function run(): BackupRun
    {
        $dir = $this->directory();

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $name = 'backup-'.now()->format('Y-m-d-His').'.sql.gz';
        $partial = "{$dir}/{$name}.part";
        $counts = [];
        $started = microtime(true);
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $mysql = $this->isMysql($connection);
        $out = null;

        try {
            $out = gzopen($partial, 'wb6') ?: throw new RuntimeException('Could not create the backup file. Is the disk full?');

            if ($mysql) {
                $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            }

            $this->write($out, '-- '.config('app.name').' database backup, '.now()->toIso8601String());
            $this->write($out, $mysql ? 'SET FOREIGN_KEY_CHECKS=0;' : 'PRAGMA foreign_keys=OFF;');

            if ($mysql) {
                $this->write($out, 'SET NAMES utf8mb4;');
            }

            foreach ($this->tables($connection) as $table) {
                $this->write($out, 'DROP TABLE IF EXISTS '.$this->name($table, $mysql).';');
                $this->write($out, $this->oneLine($this->createStatement($connection, $table)).';');
                $counts[$table] = $this->dumpRows($pdo, $out, $table, $mysql);
            }

            if (! $mysql) {
                foreach ($connection->select("SELECT sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL") as $index) {
                    $this->write($out, $this->oneLine($index->sql).';');
                }
            }

            $this->write($out, $mysql ? 'SET FOREIGN_KEY_CHECKS=1;' : 'PRAGMA foreign_keys=ON;');
            $this->write($out, '-- end of backup');

            if ($mysql) {
                $pdo->exec('COMMIT');
            }

            gzclose($out);
            $out = null;

            if (config('backups.encrypt', true)) {
                BackupCrypto::encrypt($partial, "{$dir}/{$name}.enc.part");
                @unlink($partial);
                rename("{$dir}/{$name}.enc.part", "{$dir}/{$name}.enc");
                $name .= '.enc';
            } else {
                rename($partial, "{$dir}/{$name}");
            }
        } catch (Throwable $e) {
            if ($out) {
                gzclose($out);
            }
            if ($mysql) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }
            @unlink($partial);
            @unlink("{$dir}/{$name}.enc.part");

            report($e);
            $run = BackupRun::create(['status' => 'failed', 'message' => mb_substr('Backup failed: '.$e->getMessage(), 0, 500)]);
            $this->alert('Tonight\'s database backup FAILED: '.$e->getMessage());

            return $run;
        }

        $run = BackupRun::create([
            'file' => $name,
            'size_bytes' => filesize("{$dir}/{$name}"),
            'tables' => count($counts),
            'rows' => array_sum($counts),
            'row_counts' => $counts,
            'status' => 'ok',
            'message' => sprintf('Copied %d tables (%s rows) in %ds.', count($counts), number_format(array_sum($counts)), (int) round(microtime(true) - $started)),
        ]);

        $this->prune();

        if (config('backups.send_to_telegram')) {
            $run->update(['sent_offsite' => $this->sendToTelegram("{$dir}/{$name}", $run)]);
        }

        return $run;
    }

    /**
     * Loads a backup into the practice database and checks every table's
     * row count matches. Never touches the live database.
     */
    public function testRestore(?BackupRun $run = null): BackupRun
    {
        $run ??= BackupRun::query()->where('status', 'ok')->latest('id')->first()
            ?? throw new RuntimeException('There is no backup to test yet.');

        $path = $this->directory().'/'.$run->file;
        $started = microtime(true);

        try {
            if (! is_file($path)) {
                throw new RuntimeException("The backup file {$run->file} is missing from the server.");
            }

            $target = $this->restoreConnection();
            $pdo = $target->getPdo();
            $mysql = $this->isMysql($target);

            $this->wipe($target);

            $plain = $path;

            if (str_ends_with($path, '.enc')) {
                $plain = $this->directory().'/restore-'.bin2hex(random_bytes(4)).'.sql.gz.tmp';
                BackupCrypto::decrypt($path, $plain);
            }

            $in = gzopen($plain, 'rb') ?: throw new RuntimeException('Could not open the backup file.');
            $sawEnd = false;

            try {
                while (($line = gzgets($in)) !== false) {
                    $line = rtrim($line, "\r\n");

                    if ($line === '-- end of backup') {
                        $sawEnd = true;
                    }

                    if ($line === '' || str_starts_with($line, '--')) {
                        continue;
                    }

                    $pdo->exec($line);
                }
            } finally {
                gzclose($in);

                if ($plain !== $path) {
                    @unlink($plain);
                }
            }

            if (! $sawEnd) {
                throw new RuntimeException('The backup file is cut short (it has no ending). It was probably not finished.');
            }

            $wrong = [];

            foreach ((array) $run->row_counts as $table => $expected) {
                $actual = (int) $pdo->query('SELECT COUNT(*) FROM '.$this->name($table, $mysql))->fetchColumn();

                if ($actual !== (int) $expected) {
                    $wrong[] = "{$table}: {$actual} of {$expected} rows";
                }
            }

            if ($wrong !== []) {
                throw new RuntimeException('Restored, but rows are missing: '.implode('; ', array_slice($wrong, 0, 5)));
            }

            $this->wipe($target);

            $run->update([
                'restore_tested_at' => now(),
                'restore_ok' => true,
                'restore_message' => sprintf('Restored %d tables and %s rows in %ds; every table matches.', $run->tables, number_format($run->rows), (int) round(microtime(true) - $started)),
            ]);
        } catch (Throwable $e) {
            report($e);
            $run->update(['restore_tested_at' => now(), 'restore_ok' => false, 'restore_message' => mb_substr($e->getMessage(), 0, 500)]);
            $this->alert("The practice restore of backup {$run->file} FAILED: {$e->getMessage()}");
        } finally {
            DB::purge('backup_restore');
        }

        return $run->refresh();
    }

    public function restoreConfigured(): bool
    {
        return filled(config('backups.restore.database'));
    }

    /** Deletes backup files older than keep_days. */
    public function prune(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('backups.keep_days', 14)))->getTimestamp();
        $deleted = 0;

        foreach (glob($this->directory().'/backup-*.sql.gz*') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * A connection to the practice database: the live connection's
     * settings with the practice database's name and login.
     *
     * @throws RuntimeException when it isn't set up, or would be the live database
     */
    private function restoreConnection(): Connection
    {
        if (! $this->restoreConfigured()) {
            throw new RuntimeException('No practice database is set up (Settings → Backups & status).');
        }

        $live = config('database.connections.'.config('database.default'));
        $settings = array_merge($live, array_filter([
            'host' => config('backups.restore.host'),
            'database' => config('backups.restore.database'),
            'username' => config('backups.restore.username'),
            'password' => config('backups.restore.password'),
        ], fn ($v) => filled($v)), ['url' => null]);

        // The one mistake that must be impossible: "practice" on the real data.
        if (($settings['database'] ?? null) === ($live['database'] ?? null) && ($settings['host'] ?? null) === ($live['host'] ?? null)) {
            throw new RuntimeException('The practice database is the LIVE database. Refusing: a practice restore wipes it first. Create a separate empty database.');
        }

        config(['database.connections.backup_restore' => $settings]);
        DB::purge('backup_restore');

        return DB::connection('backup_restore');
    }

    /** Removes every table from the practice database. */
    private function wipe(Connection $target): void
    {
        $mysql = $this->isMysql($target);
        $target->getPdo()->exec($mysql ? 'SET FOREIGN_KEY_CHECKS=0' : 'PRAGMA foreign_keys=OFF');

        foreach ($this->tables($target) as $table) {
            $target->getPdo()->exec('DROP TABLE IF EXISTS '.$this->name($table, $mysql));
        }

        $target->getPdo()->exec($mysql ? 'SET FOREIGN_KEY_CHECKS=1' : 'PRAGMA foreign_keys=ON');
    }

    /** @return list<string> */
    private function tables(Connection $connection): array
    {
        if ($this->isMysql($connection)) {
            return collect($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
                ->map(fn ($row) => (string) array_values((array) $row)[0])
                ->values()->all();
        }

        return collect($connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
            ->pluck('name')->all();
    }

    private function createStatement(Connection $connection, string $table): string
    {
        if ($this->isMysql($connection)) {
            $row = (array) $connection->selectOne('SHOW CREATE TABLE '.$this->name($table, true));

            return (string) ($row['Create Table'] ?? array_values($row)[1]);
        }

        return (string) $connection->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
    }

    /** Streams one table's rows into the file. Returns how many rows. */
    private function dumpRows(PDO $pdo, $out, string $table, bool $mysql): int
    {
        $buffered = null;

        if ($mysql) {
            // Row by row from the server, so a big table never has to fit in memory.
            $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        $count = 0;
        $batch = [];
        $columns = null;

        try {
            $statement = $pdo->query('SELECT * FROM '.$this->name($table, $mysql));

            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $columns ??= implode(', ', array_map(fn ($c) => $this->name($c, $mysql), array_keys($row)));
                $batch[] = '('.implode(', ', array_map(fn ($v) => $this->value($v, $mysql), array_values($row))).')';
                $count++;

                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $this->write($out, 'INSERT INTO '.$this->name($table, $mysql)." ({$columns}) VALUES ".implode(', ', $batch).';');
                    $batch = [];
                }
            }

            $statement->closeCursor();

            if ($batch !== []) {
                $this->write($out, 'INSERT INTO '.$this->name($table, $mysql)." ({$columns}) VALUES ".implode(', ', $batch).';');
            }
        } finally {
            if ($mysql) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
        }

        return $count;
    }

    /** One value, written so the whole statement stays on one line. */
    private function value(mixed $value, bool $mysql): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = (string) $value;

        // Bytes that aren't text (images, encrypted data) go as hex.
        if (! mb_check_encoding($value, 'UTF-8')) {
            return $mysql ? '0x'.bin2hex($value) : "X'".bin2hex($value)."'";
        }

        if ($mysql) {
            return "'".strtr($value, ['\\' => '\\\\', "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "'" => "\\'", "\x1a" => '\\Z'])."'";
        }

        return str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")
            ? "CAST(X'".bin2hex($value)."' AS TEXT)"
            : "'".str_replace("'", "''", $value)."'";
    }

    private function name(string $name, bool $mysql): string
    {
        return $mysql ? '`'.str_replace('`', '``', $name).'`' : '"'.str_replace('"', '""', $name).'"';
    }

    private function oneLine(string $sql): string
    {
        return trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $sql));
    }

    private function write($out, string $line): void
    {
        if (gzwrite($out, $line."\n") === false) {
            throw new RuntimeException('Writing the backup file failed. Is the disk full?');
        }
    }

    private function isMysql(Connection $connection): bool
    {
        return in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function sendToTelegram(string $path, BackupRun $run): bool
    {
        $token = config('services.telegram.bot_token');
        $chats = (array) config('services.telegram.admin_chat_ids', []);

        if (! $token || $chats === []) {
            return false;
        }

        if (filesize($path) > self::TELEGRAM_MAX_BYTES) {
            $this->alert('Tonight\'s backup is too big for Telegram ('.round(filesize($path) / 1048576).' MB). It is saved on the server only: download it with cPanel → File Manager from storage/app/backups.');

            return false;
        }

        $sent = true;

        foreach ($chats as $chat) {
            try {
                $response = Http::timeout(120)
                    ->attach('document', file_get_contents($path), basename($path))
                    ->post("https://api.telegram.org/bot{$token}/sendDocument", [
                        'chat_id' => $chat,
                        'caption' => config('app.name').' database backup. '.$run->message,
                    ]);
                $sent = $sent && $response->successful();
            } catch (Throwable $e) {
                report($e);
                $sent = false;
            }
        }

        return $sent;
    }

    private function alert(string $message): void
    {
        try {
            Notification::send(new AnonymousNotifiable, new SystemProblemAdminAlert($message));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
