<?php

namespace Tests\Feature\MySql;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Services\InvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Real concurrency: several PHP processes, each with its own MySQL
 * connection, run the same operation at the same instant (tests/Concurrency/worker.php
 * waits for a shared start time). SQLite has no row locks, so this only runs on MySQL.
 *
 * The data must be committed for the workers to see it, so these tests can't
 * use RefreshDatabase's wrapping transaction. They clean the tables instead.
 */
#[Group('mysql')]
class ConcurrencyTest extends TestCase
{
    private const WORKERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MySQL: SQLite has no row-level locking.');
        }

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }

        $this->cleanTables();
        $this->seed(ChartOfAccountsSeeder::class);
    }

    protected function tearDown(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->cleanTables();
        }

        parent::tearDown();
    }

    public function test_concurrent_payments_cannot_overpay_an_invoice(): void
    {
        $invoice = $this->issuedInvoice(); // 118.00

        $results = $this->runConcurrently('pay', fn (int $i) => ['invoice_id' => $invoice->id, 'amount' => '50.00', 'key' => "psp-{$i}"]);

        // Only two payments of 50.00 fit into 118.00. Without the row lock,
        // every worker would read outstanding = 118.00 and all would succeed.
        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(self::WORKERS - 2, collect($results)->where('code', 'billing.overpayment')->count());

        $invoice->refresh();
        $this->assertSame(10000, $invoice->amount_paid->minor);
        $this->assertSame(2, Payment::count());
        $this->assertSame(10000, $this->balance('bank'));
        $this->assertSame(1800, $this->balance('receivable'));
    }

    public function test_concurrent_retries_with_one_idempotency_key_record_one_payment(): void
    {
        $invoice = $this->issuedInvoice();

        $results = $this->runConcurrently('pay', fn () => ['invoice_id' => $invoice->id, 'amount' => '50.00', 'key' => 'psp-same']);

        $this->assertSame(self::WORKERS, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, collect($results)->pluck('result')->unique());
        $this->assertSame(1, Payment::count());
        $this->assertSame(5000, $this->balance('bank'));
    }

    /**
     * Manual entries have no row to lock; the unique index on
     * journal_entries.idempotency_key decides, and the losers replay.
     */
    public function test_concurrent_manual_entries_with_one_key_post_once(): void
    {
        $args = [
            'debit_account_id' => $this->accountId('6000'),
            'credit_account_id' => $this->accountId((string) config('ledger.accounts.bank')),
            'key' => 'client-retry-1',
        ];

        $results = $this->runConcurrently('post_manual', fn () => $args);

        $this->assertSame(self::WORKERS, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, collect($results)->pluck('result')->unique());
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_concurrent_issues_get_unique_consecutive_numbers(): void
    {
        $drafts = collect(range(1, self::WORKERS))->map(fn () => Invoice::factory()->withLines([[1, 1000]])->create());

        $results = $this->runConcurrently('issue', fn (int $i) => ['invoice_id' => $drafts[$i]->id]);

        $this->assertSame(self::WORKERS, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));

        $numbers = collect($results)->pluck('result')->sort()->values()->all();
        $expected = array_map(fn (int $n) => 'INV-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT), range(1, self::WORKERS));
        $this->assertSame($expected, $numbers);
    }

    /**
     * @param  callable(int): array<string, mixed>  $args
     * @return list<array<string, mixed>> {ok: bool, result?: mixed, code?: string} per worker
     */
    private function runConcurrently(string $operation, callable $args): array
    {
        $connection = config('database.connections.'.config('database.default'));
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'null',
            'MAIL_MAILER' => 'array',
        ];

        // Enough time for every process to boot before the shared start.
        $startAt = (string) (microtime(true) + 4);
        $worker = base_path('tests/Concurrency/worker.php');

        $pool = Process::pool(function (Pool $pool) use ($operation, $args, $env, $startAt, $worker) {
            foreach (range(0, self::WORKERS - 1) as $i) {
                $pool->as((string) $i)->env($env)->timeout(60)
                    ->command([PHP_BINARY, $worker, $startAt, $operation, json_encode($args($i), JSON_THROW_ON_ERROR)]);
            }
        })->start()->wait();

        return array_values(collect($pool)->map(function ($result) {
            $json = json_decode(trim($result->output()), true);

            return is_array($json) ? $json : ['ok' => false, 'code' => 'no_output', 'output' => $result->output().$result->errorOutput()];
        })->all());
    }

    private function issuedInvoice(): Invoice
    {
        return app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000, 1800]])->create());
    }

    private function accountId(string $code): int
    {
        return (int) Account::query()->where('code', $code)->value('id');
    }

    private function balance(string $key): int
    {
        $accounts = app(AccountRepositoryInterface::class);

        return $accounts->balance($accounts->findByCode((string) config("ledger.accounts.{$key}")))->minor;
    }

    private function cleanTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (['bank_transactions', 'payments', 'invoice_lines', 'invoices', 'customers', 'journal_lines', 'journal_entries', 'accounts', 'sequences', 'outbox_messages', 'activity_log', 'users', 'personal_access_tokens', 'jobs', 'cache', 'cache_locks'] as $table) {
            DB::table($table)->truncate();
        }

        Schema::enableForeignKeyConstraints();

        // As the 2026_10_19_000003 migration leaves it.
        DB::table('sequences')->insert(['name' => 'invoice', 'next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
}
