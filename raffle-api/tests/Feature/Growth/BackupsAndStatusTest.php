<?php

namespace Tests\Feature\Growth;

use App\Models\BackupRun;
use App\Models\Legacy\WpUser;
use App\Notifications\SystemProblemAdminAlert;
use App\Services\Monitoring\DatabaseBackup;
use App\Services\Monitoring\HealthReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BackupsAndStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $practiceDb;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->practiceDb = tempnam(sys_get_temp_dir(), 'rk-restore-').'.sqlite';
        touch($this->practiceDb);
        config(['backups.restore.database' => $this->practiceDb]);

        Notification::fake();
    }

    protected function tearDown(): void
    {
        @unlink($this->practiceDb);

        foreach (glob(storage_path('app/backups/backup-*')) ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_backup_copies_every_table_and_a_practice_restore_proves_it(): void
    {
        // Awkward values: quotes, a new line, and bytes that aren't text.
        WpUser::create(['user_login' => "o'brien", 'user_pass' => "line1\nline2", 'user_email' => 'a@example.com', 'display_name' => "Ada \"Queen\" O'Brien"]);
        DB::table('app_settings')->insert(['key' => 'binary.test', 'value' => "\x00\xff\xfe raw", 'created_at' => now(), 'updated_at' => now()]);

        $run = app(DatabaseBackup::class)->run();

        $this->assertSame('ok', $run->status, (string) $run->message);
        $this->assertFileExists(storage_path('app/backups/'.$run->file));
        $this->assertGreaterThan(20, $run->tables);
        $this->assertSame(1, $run->row_counts['wp_users']);

        $tested = app(DatabaseBackup::class)->testRestore($run);

        $this->assertTrue($tested->restore_ok, (string) $tested->restore_message);
        $this->assertStringContainsString('every table matches', $tested->restore_message);
    }

    public function test_the_restored_copy_has_the_same_values(): void
    {
        WpUser::create(['user_login' => "o'brien", 'user_pass' => "line1\nline2", 'user_email' => 'a@example.com', 'display_name' => 'Ada']);
        $run = app(DatabaseBackup::class)->run();

        // Load it by hand into the practice database and read it back.
        config(['database.connections.check' => ['driver' => 'sqlite', 'database' => $this->practiceDb, 'prefix' => '', 'foreign_key_constraints' => false]]);
        $pdo = DB::connection('check')->getPdo();
        $in = gzopen(storage_path('app/backups/'.$run->file), 'rb');
        while (($line = gzgets($in)) !== false) {
            $line = rtrim($line, "\n");
            if ($line !== '' && ! str_starts_with($line, '--')) {
                $pdo->exec($line);
            }
        }
        gzclose($in);

        $user = DB::connection('check')->table('wp_users')->first();
        $this->assertSame("o'brien", $user->user_login);
        $this->assertSame("line1\nline2", $user->user_pass);
    }

    public function test_a_cut_short_backup_fails_its_practice_restore_and_alerts_staff(): void
    {
        $run = app(DatabaseBackup::class)->run();
        $path = storage_path('app/backups/'.$run->file);
        $in = gzopen($path, 'rb');
        $lines = [];
        while (count($lines) < 10 && ($line = gzgets($in)) !== false) {
            $lines[] = $line;
        }
        gzclose($in);
        $gz = gzopen($path, 'wb');
        gzwrite($gz, implode('', $lines));
        gzclose($gz);

        $tested = app(DatabaseBackup::class)->testRestore($run);

        $this->assertFalse($tested->restore_ok);
        $this->assertStringContainsString('cut short', $tested->restore_message);
        Notification::assertSentOnDemand(SystemProblemAdminAlert::class);
    }

    public function test_the_practice_restore_refuses_to_use_the_live_database(): void
    {
        config(['backups.restore.database' => config('database.connections.sqlite.database')]);
        $run = app(DatabaseBackup::class)->run();

        $tested = app(DatabaseBackup::class)->testRestore($run);

        $this->assertFalse($tested->restore_ok);
        $this->assertStringContainsString('LIVE database', $tested->restore_message);
    }

    public function test_old_backups_are_deleted_and_a_copy_can_go_to_telegram(): void
    {
        config(['backups.send_to_telegram' => true, 'services.telegram.bot_token' => 'tok', 'services.telegram.admin_chat_ids' => ['42']]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        @mkdir(storage_path('app/backups'), 0700, true);
        $old = storage_path('app/backups/backup-2000-01-01-000000.sql.gz');
        touch($old, now()->subDays(30)->getTimestamp());

        $run = app(DatabaseBackup::class)->run();

        $this->assertFileDoesNotExist($old);
        $this->assertTrue($run->sent_offsite);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendDocument'));
    }

    public function test_system_health_reports_backups_and_restores(): void
    {
        $rows = collect(app(HealthReport::class)->run())->keyBy(1);
        $this->assertSame('WARNING', $rows['Database backups'][0]);

        BackupRun::create(['file' => 'x', 'status' => 'ok', 'restore_tested_at' => now(), 'restore_ok' => true, 'restore_message' => 'fine']);
        $rows = collect(app(HealthReport::class)->run())->keyBy(1);
        $this->assertSame('OK', $rows['Database backups'][0]);
        $this->assertSame('OK', $rows['Backup practice restore'][0]);

        BackupRun::create(['status' => 'failed', 'message' => 'disk full']);
        $rows = collect(app(HealthReport::class)->run())->keyBy(1);
        $this->assertSame('CRITICAL', $rows['Database backups'][0]);
    }

    public function test_the_status_page_shows_paused_parts_and_the_staff_message(): void
    {
        $this->get('/status')->assertNotFound();
        $this->getJson('/api/status')->assertNotFound();

        config([
            'features.status_page' => true,
            'site.switches.deposits' => false,
            'status.level' => 'degraded',
            'status.message' => 'Card top-ups are slow. Your money is safe.',
        ]);

        $this->getJson('/api/status')->assertOk()->assertJson([
            'overall' => 'degraded',
            'message' => 'Card top-ups are slow. Your money is safe.',
            'parts' => [['name' => 'Buying tickets', 'state' => 'working'], ['name' => 'Wallet top-ups', 'state' => 'paused']],
        ]);

        $this->get('/raffles')->assertInertia(fn ($page) => $page->where('site.status_banner.message', 'Card top-ups are slow. Your money is safe.'));

        config(['status.level' => 'ok']);
        $this->get('/raffles')->assertInertia(fn ($page) => $page->where('site.status_banner', null));
    }

    public function test_the_status_page_stays_open_during_maintenance(): void
    {
        config(['features.status_page' => true, 'site.maintenance.enabled' => true]);

        $this->get('/raffles')->assertStatus(503);
        $this->getJson('/api/status')->assertOk()->assertJson(['overall' => 'maintenance']);
    }
}
