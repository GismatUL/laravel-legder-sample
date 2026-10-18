<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ReconcileBankTransactions;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;

class ReportAndBankApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
    }

    public function test_trial_balance(): void
    {
        $this->issuedInvoice();

        $this->getJson('/api/v1/reports/trial-balance')
            ->assertOk()
            ->assertJsonPath('data.balanced', true)
            ->assertJsonPath('data.total_debit.amount', '118.00')
            ->assertJsonPath('data.total_credit.amount', '118.00')
            ->assertJsonPath('data.accounts.0.code', '1100')
            ->assertJsonPath('data.accounts.0.debit.amount', '118.00');

        $this->getJson('/api/v1/reports/trial-balance?as_of='.today()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data.accounts')
            ->assertJsonPath('data.total_debit.amount', '0.00');
    }

    public function test_imports_a_statement_and_queues_matching(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/bank-statements', ['statement' => $this->csv("2026-10-01,50.00,Rent\n")])
            ->assertAccepted()
            ->assertExactJson(['imported' => 1, 'reconciliation_queued' => true]);

        Queue::assertPushed(ReconcileBankTransactions::class);
    }

    public function test_reconciled_transactions_show_the_matched_invoice(): void
    {
        $invoice = $this->issuedInvoice();
        app(PaymentService::class)->record($invoice, Money::parse('118.00'), today(), 'bank_transfer', 'p-1');

        // The queue runs synchronously in tests.
        $this->postJson('/api/v1/bank-statements', [
            'statement' => $this->csv(today()->toDateString().",118.00,Transfer {$invoice->number}\n".today()->toDateString().",9.99,Unknown\n"),
        ])->assertAccepted();

        $this->getJson('/api/v1/bank-transactions?reconciled=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', $invoice->number);

        $this->getJson('/api/v1/bank-transactions?reconciled=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'Unknown');
    }

    public function test_a_malformed_statement_is_a_validation_error(): void
    {
        $this->postJson('/api/v1/bank-statements', ['statement' => $this->csv("not-a-date,abc,Rent\n")])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statement');

        $this->assertDatabaseCount('bank_transactions', 0);
    }

    private function csv(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('statement.csv', "date,amount,reference\n".$rows);
    }
}
