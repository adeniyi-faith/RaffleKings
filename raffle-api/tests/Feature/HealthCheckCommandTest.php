<?php

namespace Tests\Feature;

use App\Console\Commands\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OVERHAUL_CHECKLIST.md item 41 — `app:health-check` must catch the
 * exact state the live site was found in (no payment keys, email set to
 * "log only", no scheduler running) and must never leak a secret value
 * into a deploy log.
 */
class HealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private function healthyConfig(): void
    {
        config([
            'services.paystack.secret_key' => 'sk_live_SUPERSECRET123',
            'services.flutterwave.secret_key' => 'FLWSECK-SUPERSECRET456',
            'services.flutterwave.secret_hash' => 'hash',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.com',
            'queue.default' => 'database',
        ]);
        Cache::put(HealthCheck::SCHEDULER_HEARTBEAT_KEY, now()->timestamp, now()->addDay());
    }

    public function test_the_live_sites_broken_state_is_reported_as_critical(): void
    {
        config([
            'services.paystack.secret_key' => null,
            'services.flutterwave.secret_key' => null,
            'mail.default' => 'log',
        ]);

        $exit = Artisan::call('app:health-check', ['--strict' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('nobody can top up', $output);
        $this->assertStringContainsString("Set to 'log'", $output);
        $this->assertStringContainsString('Has never run', $output);
    }

    public function test_without_strict_it_reports_but_never_fails_the_deploy(): void
    {
        config(['mail.default' => 'log']);

        $this->assertSame(0, Artisan::call('app:health-check'));
    }

    public function test_a_healthy_setup_passes_strict_mode(): void
    {
        $this->healthyConfig();

        $this->assertSame(0, Artisan::call('app:health-check', ['--strict' => true]));
    }

    public function test_it_never_prints_a_secret_value(): void
    {
        $this->healthyConfig();

        Artisan::call('app:health-check');
        $output = Artisan::output();

        $this->assertStringNotContainsString('SUPERSECRET', $output);
        $this->assertStringContainsString('PAYSTACK_SECRET_KEY', $output);
    }

    public function test_a_stale_scheduler_heartbeat_is_critical(): void
    {
        $this->healthyConfig();
        Cache::put(HealthCheck::SCHEDULER_HEARTBEAT_KEY, now()->subMinutes(30)->timestamp, now()->addDay());

        $this->assertSame(1, Artisan::call('app:health-check', ['--strict' => true]));
        $this->assertStringContainsString('seems to have stopped', Artisan::output());
    }

    public function test_a_queue_job_waiting_more_than_five_minutes_is_critical(): void
    {
        $this->healthyConfig();
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->subMinutes(10)->timestamp,
            'created_at' => now()->subMinutes(10)->timestamp,
        ]);

        $this->assertSame(1, Artisan::call('app:health-check', ['--strict' => true]));
        $this->assertStringContainsString('Nothing is sending them', Artisan::output());
    }
}
