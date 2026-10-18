<?php

namespace Tests\Feature;

use App\Jobs\ReconcileBankTransactions;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Services\BankStatementImporter;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;

class BankReconciliationTest extends LedgerTestCase
{
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invoice = app(InvoiceService::class)->issue(
            Invoice::factory()->withLines([[1, 10000, 1800]])->create(),
        );
        app(PaymentService::class)->record($this->invoice, Money::parse('118.00'), today(), 'bank_transfer', 'p-1');
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
