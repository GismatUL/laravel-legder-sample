<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Exceptions\BillingException;
use App\Exceptions\LedgerException;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;

class InvoiceServiceTest extends LedgerTestCase
{
    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invoices = app(InvoiceService::class);
    }

    public function test_calculates_totals_with_per_line_tax_rounding(): void
    {
        // 3 x 33.33 = 99.99, 18% = 17.9982 -> 18.00; 1 x 0.25, 18% = 0.045 -> 0.05
        $invoice = Invoice::factory()->withLines([[3, 3333, 1800], [1, 25, 1800]])->create();

        $this->invoices->recalculate($invoice);

        $this->assertSame(10024, $invoice->subtotal->minor);
        $this->assertSame(1805, $invoice->tax_total->minor);
        $this->assertSame(11829, $invoice->total->minor);
    }

    public function test_issuing_assigns_a_number_and_posts_to_the_ledger(): void
    {
        $invoice = $this->invoices->issue(
            Invoice::factory()->withLines([[2, 50000, 1800]])->create(),
        );

        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('INV-000001', $invoice->number);
        $this->assertNotNull($invoice->due_date);

        $this->assertTrue($this->balance('receivable')->equals(Money::parse('1180.00')));
        $this->assertTrue($this->balance('sales')->equals(Money::parse('1000.00')));
        $this->assertTrue($this->balance('vat_payable')->equals(Money::parse('180.00')));
    }

    public function test_invoice_numbers_are_sequential(): void
    {
        $numbers = collect(range(1, 3))->map(fn () => $this->invoices->issue(
            Invoice::factory()->withLines([[1, 1000]])->create(),
        )->number);

        $this->assertSame(['INV-000001', 'INV-000002', 'INV-000003'], $numbers->all());
    }

    public function test_an_invoice_cannot_be_issued_twice(): void
    {
        $invoice = $this->invoices->issue(Invoice::factory()->withLines([[1, 1000]])->create());

        $this->expectException(BillingException::class);

        $this->invoices->issue($invoice);
    }

    public function test_an_empty_invoice_cannot_be_issued(): void
    {
        $this->expectException(BillingException::class);

        $this->invoices->issue(Invoice::factory()->create());
    }

    public function test_a_failed_issue_rolls_back_the_invoice_number(): void
    {
        // The ledger refuses the posting after a number was already taken.
        $this->account('sales')->update(['is_active' => false]);

        try {
            $this->invoices->issue(Invoice::factory()->withLines([[1, 1000]])->create());
            $this->fail('Issue should have failed.');
        } catch (LedgerException) {
        }

        $this->account('sales')->update(['is_active' => true]);

        $invoice = $this->invoices->issue(Invoice::factory()->withLines([[1, 1000]])->create());

        $this->assertSame('INV-000001', $invoice->number);
    }

    public function test_voiding_reverses_the_posting(): void
    {
        $invoice = $this->invoices->issue(Invoice::factory()->withLines([[1, 10000, 1800]])->create());

        $invoice = $this->invoices->void($invoice);

        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertNotNull($invoice->void_entry_id);
        $this->assertTrue($this->balance('receivable')->isZero());
        $this->assertTrue($this->balance('sales')->isZero());
        $this->assertTrue($this->balance('vat_payable')->isZero());
    }

    public function test_an_invoice_with_payments_cannot_be_voided(): void
    {
        $invoice = $this->invoices->issue(Invoice::factory()->withLines([[1, 10000]])->create());
        app(PaymentService::class)->record($invoice, Money::parse('10.00'), today(), 'cash', 'p-1');

        $this->expectException(BillingException::class);

        $this->invoices->void($invoice);
    }
}
