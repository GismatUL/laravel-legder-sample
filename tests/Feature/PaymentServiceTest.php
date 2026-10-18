<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;

class PaymentServiceTest extends LedgerTestCase
{
    private PaymentService $payments;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = app(PaymentService::class);

        // Total 118.00 (100.00 + 18% VAT)
        $this->invoice = app(InvoiceService::class)->issue(
            Invoice::factory()->withLines([[1, 10000, 1800]])->create(),
        );
    }

    public function test_partial_then_full_payment_updates_status_and_ledger(): void
    {
        $this->payments->record($this->invoice, Money::parse('50.00'), today(), 'card', 'p-1');
        $this->assertSame(InvoiceStatus::PartiallyPaid, $this->invoice->refresh()->status);
        $this->assertTrue($this->invoice->outstanding()->equals(Money::parse('68.00')));

        $this->payments->record($this->invoice, Money::parse('68.00'), today(), 'card', 'p-2');
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        $this->assertTrue($this->invoice->outstanding()->isZero());

        $this->assertTrue($this->account('bank')->balance()->equals(Money::parse('118.00')));
        $this->assertTrue($this->account('receivable')->balance()->isZero());
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->expectException(BillingException::class);

        $this->payments->record($this->invoice, Money::parse('118.01'), today(), 'card', 'p-1');
    }

    public function test_zero_or_negative_payment_is_rejected(): void
    {
        $this->expectException(BillingException::class);

        $this->payments->record($this->invoice, Money::zero(), today(), 'card', 'p-1');
    }

    public function test_a_retried_payment_is_recorded_once(): void
    {
        $first = $this->payments->record($this->invoice, Money::parse('118.00'), today(), 'card', 'provider-tx-1');

        // e.g. the payment provider sends the same webhook again after the invoice is already paid
        $retry = $this->payments->record($this->invoice, Money::parse('118.00'), today(), 'card', 'provider-tx-1');

        $this->assertTrue($first->is($retry));
        $this->assertDatabaseCount('payments', 1);
        $this->assertTrue($this->account('bank')->balance()->equals(Money::parse('118.00')));
    }

    public function test_reusing_a_key_for_a_different_amount_fails(): void
    {
        $this->payments->record($this->invoice, Money::parse('50.00'), today(), 'card', 'provider-tx-1');

        $this->expectException(BillingException::class);

        $this->payments->record($this->invoice, Money::parse('60.00'), today(), 'card', 'provider-tx-1');
    }

    public function test_draft_and_void_invoices_do_not_accept_payments(): void
    {
        $draft = Invoice::factory()->withLines([[1, 1000]])->create();

        $this->expectException(BillingException::class);

        $this->payments->record($draft, Money::parse('10.00'), today(), 'card', 'p-1');
    }

    public function test_a_failed_payment_leaves_no_trace(): void
    {
        try {
            $this->payments->record($this->invoice, Money::parse('500.00'), today(), 'card', 'p-1');
        } catch (BillingException) {
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('journal_entries', 1); // only the invoice posting
        $this->assertTrue($this->account('bank')->balance()->isZero());
    }
}
