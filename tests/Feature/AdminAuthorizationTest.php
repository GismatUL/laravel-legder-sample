<?php

namespace Tests\Feature;

use App\Data\ManualEntryLineData;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Jobs\ReconcileBankTransactions;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The admin panel enforces the same policies as the API. Hidden buttons are
 * not the protection: the pages and actions refuse the request server-side.
 */
class AdminAuthorizationTest extends LedgerTestCase
{
    private function actingAsRole(UserRole $role): void
    {
        $this->actingAs(User::factory()->role($role)->create());
    }

    public function test_a_viewer_can_open_list_pages_but_not_create_or_edit_pages(): void
    {
        $draft = Invoice::factory()->withLines([[1, 1000]])->create();
        $this->actingAsRole(UserRole::Viewer);

        $this->get(InvoiceResource::getUrl('index'))->assertOk();
        $this->get(InvoiceResource::getUrl('view', ['record' => $draft]))->assertOk();
        $this->get(JournalEntryResource::getUrl('index'))->assertOk();

        $this->get(InvoiceResource::getUrl('create'))->assertForbidden();
        $this->get(InvoiceResource::getUrl('edit', ['record' => $draft]))->assertForbidden();
        $this->get(CustomerResource::getUrl('create'))->assertForbidden();
        $this->get(JournalEntryResource::getUrl('create'))->assertForbidden();
        $this->get(AccountResource::getUrl('create'))->assertForbidden();
    }

    public function test_an_accountant_cannot_change_the_chart_of_accounts(): void
    {
        $this->actingAsRole(UserRole::Accountant);

        $this->get(AccountResource::getUrl('index'))->assertOk();
        $this->get(AccountResource::getUrl('create'))->assertForbidden();
        $this->get(AccountResource::getUrl('edit', ['record' => $this->accountByCode('6000')]))->assertForbidden();
        $this->get(InvoiceResource::getUrl('create'))->assertOk();
    }

    public function test_a_viewer_cannot_run_invoice_workflow_actions(): void
    {
        $draft = Invoice::factory()->withLines([[1, 10000]])->create();
        $this->actingAsRole(UserRole::Viewer);

        // callAction() would stop at "action is hidden"; a crafted Livewire
        // request calls the component methods directly.
        Livewire::test(ViewInvoice::class, ['record' => $draft->getRouteKey()])
            ->assertActionHidden('issue')
            ->call('mountAction', 'issue')
            ->call('callMountedAction');

        $this->assertSame(InvoiceStatus::Draft, $draft->refresh()->status);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_viewer_cannot_record_a_payment_or_void(): void
    {
        $invoice = app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000]])->create());
        $this->actingAsRole(UserRole::Viewer);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertActionHidden('recordPayment')
            ->assertActionHidden('void')
            ->call('mountAction', 'recordPayment')
            ->call('callMountedAction')
            ->call('mountAction', 'void')
            ->call('callMountedAction');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(InvoiceStatus::Issued, $invoice->refresh()->status);
    }

    public function test_a_viewer_cannot_reverse_a_journal_entry(): void
    {
        $entry = app(LedgerService::class)->postManual(today(), 'Supplies', [
            new ManualEntryLineData($this->accountByCode('6000')->id, Money::parse('5.00'), Money::zero()),
            new ManualEntryLineData($this->account('bank')->id, Money::zero(), Money::parse('5.00')),
        ]);
        $this->actingAsRole(UserRole::Viewer);

        Livewire::test(ViewJournalEntry::class, ['record' => $entry->getRouteKey()])
            ->assertActionHidden('reverse')
            ->call('mountAction', 'reverse')
            ->call('callMountedAction');

        $this->assertFalse($entry->reversedBy()->exists());
    }

    /**
     * Regression: the import and matching actions on the bank page had no
     * authorization, so a viewer could import statements.
     */
    public function test_a_viewer_cannot_import_statements_or_run_matching(): void
    {
        Queue::fake();
        $this->actingAsRole(UserRole::Viewer);

        Livewire::test(ListBankTransactions::class)
            ->assertActionHidden('import')
            ->assertActionHidden('reconcile')
            ->call('mountAction', 'reconcile')
            ->call('callMountedAction');

        Queue::assertNothingPushed();
    }

    public function test_an_accountant_can_run_matching(): void
    {
        Queue::fake();
        $this->actingAsRole(UserRole::Accountant);

        Livewire::test(ListBankTransactions::class)
            ->assertActionVisible('import')
            ->callAction('reconcile');

        Queue::assertPushed(ReconcileBankTransactions::class);
    }

    public function test_editing_an_account_goes_through_the_account_service(): void
    {
        // Posting to 6000 locks its code and type.
        app(LedgerService::class)->postManual(today(), 'Supplies', [
            new ManualEntryLineData($this->accountByCode('6000')->id, Money::parse('5.00'), Money::zero()),
            new ManualEntryLineData($this->account('bank')->id, Money::zero(), Money::parse('5.00')),
        ]);
        $this->actingAsRole(UserRole::Admin);
        $account = $this->accountByCode('6000');

        Livewire::test(EditAccount::class, ['record' => $account->getRouteKey()])
            ->fillForm(['name' => 'Office & supplies'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('Office & supplies', $account->name);
        $this->assertSame('6000', $account->code);
    }
}
