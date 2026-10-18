<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Jobs\ReconcileBankTransactions;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\BankReconciler;
use App\Services\BankStatementImporter;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

class BankReconciliationTest extends LedgerTestCase
{
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invoice = app(InvoiceService::class)->issue(
            Invoice::factory()->withLines([[1, 10000, 1800]])->create(),
        );
        app(PaymentService::class)->record($this->invoice, Money::parse('118.00'), today(), PaymentMethod::BankTransfer, 'p-1');
    }

    private function statement(string $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($path, "date,amount,reference\n".$rows);

        return $path;
    }

    public function test_import_queues_reconciliation(): void
    {
        Queue::fake();

        $count = app(BankStatementImporter::class)->import(
            $this->statement(today()->toDateString().",118.00,Transfer {$this->invoice->number}\n"),
        );

        $this->assertSame(1, $count);
        Queue::assertPushed(ReconcileBankTransactions::class);
    }

    public function test_importing_the_same_statement_twice_creates_no_duplicates(): void
    {
        Queue::fake();
        $path = $this->statement("2026-10-01,50.00,Rent\n2026-10-01,50.00,Rent\n");

        $this->assertSame(2, app(BankStatementImporter::class)->import($path));
        $this->assertSame(0, app(BankStatementImporter::class)->import($path));
        $this->assertDatabaseCount('bank_transactions', 2);
    }

    public function test_matches_by_invoice_number_and_exact_amount(): void
    {
        $date = today()->toDateString();

        app(BankStatementImporter::class)->import($this->statement(
            "{$date},118.00,Transfer {$this->invoice->number}\n".  // matches
            "{$date},117.00,Transfer {$this->invoice->number}\n".  // wrong amount
            "{$date},118.00,No reference\n",                       // no invoice number
        ));

        // The queue runs synchronously in tests, so the job has already run.
        $matched = BankTransaction::query()->whereNotNull('reconciled_at')->get();

        $this->assertCount(1, $matched);
        $this->assertSame(11800, $matched->first()->amount->minor);
        $this->assertTrue($matched->first()->payment->invoice->is($this->invoice));
        $this->assertSame(2, BankTransaction::unreconciled()->count());
    }

    public function test_a_reference_longer_than_the_column_is_rejected_before_inserting(): void
    {
        // Regression: SQLite accepted it, MySQL strict mode failed the whole batch with a 500.
        $path = $this->statement("2026-10-01,10.00,ok\n2026-10-02,20.00,".str_repeat('x', 256)."\n");

        $this->expectExceptionObject(new InvalidArgumentException('Line 3: reference is longer than 255 characters.'));

        try {
            app(BankStatementImporter::class)->import($path);
        } finally {
            $this->assertDatabaseCount('bank_transactions', 0);
        }
    }

    /** @return array<string, array{string}> */
    public static function malformedRows(): array
    {
        return [
            'bad date' => ['2026-13-01,10.00,x'],
            'float notation' => ['2026-10-01,1e3,x'],
            'too many decimals' => ['2026-10-01,10.001,x'],
            'missing column' => ['2026-10-01,10.00'],
            'amount overflow' => ['2026-10-01,99999999999999999999,x'],
        ];
    }

    #[DataProvider('malformedRows')]
    public function test_a_malformed_row_rejects_the_whole_statement(string $row): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            app(BankStatementImporter::class)->import($this->statement("2026-10-01,10.00,ok\n{$row}\n"));
        } finally {
            $this->assertDatabaseCount('bank_transactions', 0);
        }
    }

    public function test_outgoing_and_unmatched_lines_stay_open_for_review(): void
    {
        $date = today()->toDateString();

        app(BankStatementImporter::class)->import($this->statement(
            "{$date},-118.00,Refund {$this->invoice->number}\n".
            "{$date},118.00,INV-999999\n",
        ));

        $this->assertSame(2, BankTransaction::unreconciled()->count());
    }

    public function test_running_the_job_again_matches_nothing_twice(): void
    {
        $date = today()->toDateString();
        app(BankStatementImporter::class)->import($this->statement("{$date},118.00,Transfer {$this->invoice->number}\n"));

        // A retry or an overlapping second run.
        ReconcileBankTransactions::dispatchSync();
        ReconcileBankTransactions::dispatchSync();

        $this->assertSame(1, BankTransaction::query()->whereNotNull('reconciled_at')->count());
    }

    /**
     * Regression: with ShouldBeUnique the lock was held while the job ran, so
     * an import during a run dispatched nothing and its rows waited for the
     * next import. Unique-until-processing releases the lock when a run starts.
     */
    public function test_an_import_during_a_running_reconciliation_queues_another_run(): void
    {
        config(['queue.default' => 'database']);

        // Stands in for "a statement is imported while the job is running".
        $this->app->bind(BankReconciler::class, fn ($app) => new class($app->make(BankTransactionRepositoryInterface::class), $app->make(PaymentRepositoryInterface::class)) extends BankReconciler
        {
            public function reconcile(BankTransaction $transaction): bool
            {
                ReconcileBankTransactions::dispatch();

                return parent::reconcile($transaction);
            }
        });

        app(BankStatementImporter::class)->import($this->statement(today()->toDateString().",5.00,unmatched\n"));
        ReconcileBankTransactions::dispatch(); // collapses into the queued run
        $this->assertDatabaseCount('jobs', 1);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertDatabaseCount('jobs', 1); // the run queued during processing
    }

    public function test_a_failed_run_is_logged_without_sql_bindings(): void
    {
        $log = Log::spy();

        $sqlError = new QueryException('mysql', 'insert into bank_transactions (reference) values (?)', ['Salary ACME IBAN AZ21NABZ0000'], new PDOException('Duplicate', 23000));

        (new ReconcileBankTransactions)->failed($sqlError);

        $log->shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Bank reconciliation failed'
            && $context['exception'] === QueryException::class
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'IBAN'));
    }

    public function test_the_job_has_retry_and_timeout_limits(): void
    {
        $job = new ReconcileBankTransactions;

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60], $job->backoff);
        $this->assertSame(120, $job->timeout);
    }

    public function test_a_payment_is_matched_to_only_one_bank_transaction(): void
    {
        $date = today()->toDateString();

        app(BankStatementImporter::class)->import($this->statement(
            "{$date},118.00,Transfer {$this->invoice->number}\n".
            "{$date},118.00,Transfer {$this->invoice->number}\n",
        ));

        $this->assertSame(1, BankTransaction::query()->whereNotNull('payment_id')->count());
        $this->assertSame(1, BankTransaction::unreconciled()->count());
    }
}
