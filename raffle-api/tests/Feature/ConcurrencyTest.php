<?php

namespace Tests\Feature;

use App\Models\LedgerJournal;
use App\Models\Wallet;
use App\Services\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real parallel requests, in separate PHP processes against a real MySQL
 * database (row locks are only real there; the quick SQLite tests can't
 * prove this). Skipped when the tests run on SQLite.
 */
class ConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs a real MySQL database (set DB_CONNECTION=mysql).');
        }

        // Real committed data (other processes must see it), so no wrapping transaction.
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    /** @param  list<string>  $keys one process per key, all started at once */
    private function race(string $call, array $keys): array
    {
        $processes = array_map(function (string $key) use ($call) {
            $code = 'try { '.str_replace('{KEY}', $key, $call).' echo "OK"; } catch (Throwable $e) { echo class_basename($e); }';
            $process = new Process([PHP_BINARY, 'artisan', 'tinker', '--execute='.$code], base_path(), array_merge($_ENV, ['APP_ENV' => 'testing']));
            $process->start();

            return $process;
        }, $keys);

        return array_map(function (Process $p) {
            $p->wait();

            return trim(strrchr(trim($p->getOutput()) ?: 'none', "\n") ?: trim($p->getOutput()));
        }, $processes);
    }

    public function test_eight_simultaneous_spends_never_take_more_than_the_balance(): void
    {
        app(WalletLedgerService::class)->credit(1, 'wallet', 1000, 'deposit', 'seed', 'gateway_clearing');

        $results = $this->race("app(App\\Services\\WalletLedgerService::class)->debit(1, 'wallet', 300, 'ticket_purchase', 'spend-{KEY}', 'ticket_sales');", range(1, 8));

        $this->assertSame(3, count(array_filter($results, fn ($r) => $r === 'OK')), json_encode($results));
        $this->assertEquals(100, Wallet::where('user_id', 1)->value('wallet_balance'));
    }

    public function test_the_same_tap_from_eight_processes_posts_once(): void
    {
        app(WalletLedgerService::class)->credit(1, 'wallet', 1000, 'deposit', 'seed', 'gateway_clearing');

        $this->race("app(App\\Services\\WalletLedgerService::class)->debit(1, 'wallet', 100, 'ticket_purchase', 'one-tap', 'ticket_sales');", range(1, 8));

        $this->assertSame(1, LedgerJournal::where('business_key', 'one-tap')->count());
        $this->assertEquals(900, Wallet::where('user_id', 1)->value('wallet_balance'));
    }
}
