<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Filament\Pages\TrialBalance;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\InvoiceService;
use Livewire\Livewire;

class AdminPanelTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_pages_render(): void
    {
        $invoice = app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000, 1800]])->create());

        Livewire::test(ListAccounts::class)->assertOk()->assertSee('Accounts receivable');
        Livewire::test(ListInvoices::class)->assertOk()->assertSee($invoice->number);
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])->assertOk()->assertSee('118.00 AZN');
        Livewire::test(ListJournalEntries::class)->assertOk();
        Livewire::test(ListBankTransactions::class)->assertOk();
        Livewire::test(TrialBalance::class)->assertOk()->assertSee('Debits equal credits');
        $this->get('/admin')->assertOk();
    }

    public function test_creates_a_draft_invoice_with_calculated_totals(): void
    {
        $customer = Customer::factory()->create();

        Livewire::test(CreateInvoice::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'lines' => [
                    ['description' => 'Consulting', 'quantity' => 4, 'unit_price' => '125.50', 'tax_rate_bp' => 1800],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::sole();

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame(50200, $invoice->subtotal->minor);
        $this->assertSame(9036, $invoice->tax_total->minor);
    }

    public function test_edits_a_draft_and_shows_existing_prices_as_decimals(): void
    {
        $invoice = Invoice::factory()->withLines([[2, 1250, 0]])->create();

        Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSchemaStateSet(fn (array $state) => $this->assertSame('12.50', array_values($state['lines'])[0]['unit_price']))
            ->fillForm([
                'customer_id' => $invoice->customer_id,
                'lines' => [
                    ['description' => 'Replaced line', 'quantity' => 3, 'unit_price' => '10.00', 'tax_rate_bp' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $invoice->refresh();
        $this->assertSame(['Replaced line'], $invoice->lines()->pluck('description')->all());
        $this->assertSame(3000, $invoice->total->minor);
    }

    public function test_issue_and_payment_actions_go_through_the_services(): void
    {
        $invoice = Invoice::factory()->withLines([[1, 10000, 1800]])->create();

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('issue')
            ->assertHasNoActionErrors()
            ->callAction('recordPayment', ['amount' => '18.00', 'paid_on' => today()->toDateString(), 'method' => 'cash'])
            ->assertHasNoActionErrors();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
        $this->assertSame(10000, $invoice->outstanding()->minor);
    }

    public function test_overpayment_shows_an_error_and_records_nothing(): void
    {
        $invoice = app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000]])->create());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('recordPayment', ['amount' => '999.00', 'paid_on' => today()->toDateString(), 'method' => 'cash'])
            ->assertNotified('Payment of 999.00 AZN exceeds the outstanding balance of 100.00 AZN.');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_manual_entry_must_balance(): void
    {
        $bank = $this->account('bank')->id;
        $expense = $this->accountByCode('6000')->id;

        Livewire::test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => today()->toDateString(),
                'description' => 'Office supplies',
                'lines' => [
                    ['account_id' => $expense, 'debit' => '50.00', 'credit' => null, 'memo' => null],
                    ['account_id' => $bank, 'debit' => null, 'credit' => '45.00', 'memo' => null],
                ],
            ])
            ->call('create')
            ->assertNotified();

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_manual_entry_can_be_posted_and_reversed(): void
    {
        $bank = $this->account('bank')->id;
        $expense = $this->accountByCode('6000')->id;

        Livewire::test(CreateJournalEntry::class)
            ->fillForm([
                'entry_date' => today()->toDateString(),
                'description' => 'Office supplies',
                'lines' => [
                    ['account_id' => $expense, 'debit' => '50.00', 'credit' => null, 'memo' => null],
                    ['account_id' => $bank, 'debit' => null, 'credit' => '50.00', 'memo' => null],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $entry = JournalEntry::sole();

        Livewire::test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->callAction('reverse');

        $this->assertTrue($entry->reversedBy()->exists());
        $this->assertTrue($this->balance('bank')->isZero());
    }
}
