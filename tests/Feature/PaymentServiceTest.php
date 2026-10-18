<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BillingException;
use App\Exceptions\LedgerException;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $this->payments->record($this->invoice, Money::parse('50.00'), today(), PaymentMethod::Card, 'p-1');
        $this->assertSame(InvoiceStatus::PartiallyPaid, $this->invoice->refresh()->status);
        $this->assertTrue($this->invoice->outstanding()->equals(Money::parse('68.00')));

        $this->payments->record($this->invoice, Money::parse('68.00'), today(), PaymentMethod::Card, 'p-2');
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
        $this->assertTrue($this->invoice->outstanding()->isZero());

        $this->assertTrue($this->balance('bank')->equals(Money::parse('118.00')));
        $this->assertTrue($this->balance('receivable')->isZero());
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->expectException(BillingException::class);

        $this->payments->record($this->invoice, Money::parse('118.01'), today(), PaymentMethod::Card, 'p-1');
    }

    public function test_zero_or_negative_payment_is_rejected(): void
    {
        $this->expectException(BillingException::class);

        $this->payments->record($this->invoice, Money::zero(), today(), PaymentMethod::Card, 'p-1');
    }

    public function test_a_retried_payment_is_recorded_once(): void
    {
        $first = $this->payments->record($this->invoice, Money::parse('118.00'), today(), PaymentMethod::Card, 'provider-tx-1');

        // e.g. the payment provider sends the same webhook again after the invoice is already paid
        $retry = $this->payments->record($this->invoice, Money::parse('118.00'), today(), PaymentMethod::Card, 'provider-tx-1');

        $this->assertTrue($first->is($retry));
        $this->assertDatabaseCount('payments', 1);
        $this->assertTrue($this->balance('bank')->equals(Money::parse('118.00')));
    }

    public function test_reusing_a_key_for_a_different_amount_fails(): void
    {
        $this->payments->record($this->invoice, Money::parse('50.00'), today(), PaymentMethod::Card, 'provider-tx-1');

        $this->expectException(BillingException::class);

        $this->payments->record($this->invoice, Money::parse('60.00'), today(), PaymentMethod::Card, 'provider-tx-1');
    }

    /** @return array<string, array{string, PaymentMethod, string|null}> */
    public static function changedPayloads(): array
    {
        return [
            'different date' => ['yesterday', PaymentMethod::Card, 'ref-1'],
            'different method' => ['today', PaymentMethod::Cash, 'ref-1'],
            'different reference' => ['today', PaymentMethod::Card, 'ref-2'],
            'reference removed' => ['today', PaymentMethod::Card, null],
        ];
    }

    /**
     * Regression: only the invoice and amount used to be compared, so these
     * retries silently returned the first payment.
     */
    #[DataProvider('changedPayloads')]
    public function test_reusing_a_key_with_any_other_field_changed_is_a_conflict(string $date, PaymentMethod $method, ?string $reference): void
    {
        $this->travelTo(today()->addDay());
        $this->payments->record($this->invoice, Money::parse('50.00'), today(), PaymentMethod::Card, 'provider-tx-1', 'ref-1');

        try {
            $this->payments->record($this->invoice, Money::parse('50.00'), $date === 'today' ? today() : today()->subDay(), $method, 'provider-tx-1', $reference);
            $this->fail('Expected an idempotency conflict.');
        } catch (BillingException $e) {
            $this->assertSame('billing.idempotency_conflict', $e->errorCode);
        }

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_reusing_a_key_on_another_invoice_is_a_conflict(): void
    {
        $other = app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000, 1800]])->create());
        $this->payments->record($this->invoice, Money::parse('50.00'), today(), PaymentMethod::Card, 'provider-tx-1');

        $this->expectExceptionObject(BillingException::idempotencyConflict('provider-tx-1'));

        $this->payments->record($other, Money::parse('50.00'), today(), PaymentMethod::Card, 'provider-tx-1');
    }

    public function test_a_payment_cannot_be_dated_before_the_invoice_was_issued(): void
    {
        try {
            $this->payments->record($this->invoice, Money::parse('10.00'), today()->subDay(), PaymentMethod::Card, 'p-1');
            $this->fail('Expected a business rule error.');
        } catch (BillingException $e) {
            $this->assertSame('billing.payment_before_issue', $e->errorCode);
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_payment_cannot_be_dated_in_the_future(): void
    {
        // The API validates this too; the service rule covers every other caller.
        $this->expectExceptionObject(BillingException::paymentInFuture());

        $this->payments->record($this->invoice, Money::parse('10.00'), today()->addDay(), PaymentMethod::Card, 'p-1');
    }

    public function test_paying_exactly_the_outstanding_balance_is_allowed_one_cent_more_is_not(): void
    {
        $this->payments->record($this->invoice, Money::parse('117.99'), today(), PaymentMethod::Card, 'p-1');

        try {
            $this->payments->record($this->invoice, Money::parse('0.02'), today(), PaymentMethod::Card, 'p-2');
            $this->fail('Expected an overpayment error.');
        } catch (BillingException $e) {
            $this->assertSame('billing.overpayment', $e->errorCode);
        }

        $this->payments->record($this->invoice, Money::parse('0.01'), today(), PaymentMethod::Card, 'p-3');
        $this->assertSame(InvoiceStatus::Paid, $this->invoice->refresh()->status);
    }

    public function test_a_paid_invoice_rejects_further_payments(): void
    {
        $this->payments->record($this->invoice, Money::parse('118.00'), today(), PaymentMethod::Card, 'p-1');

        $this->expectExceptionObject(BillingException::invalidTransition(InvoiceStatus::Paid, 'record a payment on'));

        $this->payments->record($this->invoice, Money::parse('1.00'), today(), PaymentMethod::Card, 'p-2');
    }

    public function test_rolls_back_the_payment_when_ledger_posting_fails(): void
    {
        // The bank account is inactive, so LedgerService refuses the posting
        // after the payment checks passed.
        $this->account('bank')->update(['is_active' => false]);

        try {
            $this->payments->record($this->invoice, Money::parse('50.00'), today(), PaymentMethod::Card, 'p-1');
            $this->fail('Expected the ledger to refuse the posting.');
        } catch (LedgerException $e) {
            $this->assertSame('ledger.inactive_account', $e->errorCode);
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(InvoiceStatus::Issued, $this->invoice->refresh()->status);
        $this->assertTrue($this->invoice->amount_paid->isZero());

        // A retry with the same key works once the cause is fixed.
        $this->account('bank')->update(['is_active' => true]);
        $this->payments->record($this->invoice, Money::parse('50.00'), today(), PaymentMethod::Card, 'p-1');
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_draft_and_void_invoices_do_not_accept_payments(): void
    {
        $draft = Invoice::factory()->withLines([[1, 1000]])->create();

        $this->expectException(BillingException::class);

        $this->payments->record($draft, Money::parse('10.00'), today(), PaymentMethod::Card, 'p-1');
    }

    public function test_a_failed_payment_leaves_no_trace(): void
    {
        try {
            $this->payments->record($this->invoice, Money::parse('500.00'), today(), PaymentMethod::Card, 'p-1');
        } catch (BillingException) {
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('journal_entries', 1); // only the invoice posting
        $this->assertTrue($this->balance('bank')->isZero());
    }
}
