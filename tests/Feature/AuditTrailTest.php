<?php

namespace Tests\Feature;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Enums\UserRole;
use App\Exceptions\LedgerException;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
use App\Support\TokenAbility;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Feature\Api\V1\ApiTestCase;

class AuditTrailTest extends ApiTestCase
{
    public function test_a_journal_entry_records_the_api_user_who_posted_it(): void
    {
        $user = $this->actingAsApiUser();

        $this->postJson('/api/v1/journal-entries', [
            'entry_date' => today()->toDateString(),
            'description' => 'Office supplies',
            'lines' => [
                ['account_id' => $this->accountByCode('6000')->id, 'debit' => '5.00'],
                ['account_id' => $this->account('bank')->id, 'credit' => '5.00'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.posted_by', ['id' => $user->id, 'name' => $user->name]);

        $this->assertSame($user->id, JournalEntry::sole()->posted_by);
    }

    public function test_document_postings_record_who_triggered_them(): void
    {
        $user = $this->actingAsApiUser();
        $invoice = $this->issuedInvoice();

        $this->withHeader('Idempotency-Key', 'psp-1')
            ->postJson("/api/v1/invoices/{$invoice->id}/payments", ['amount' => '10.00', 'paid_on' => today()->toDateString(), 'method' => 'cash'])
            ->assertCreated();

        // Issue entry and payment entry
        $this->assertSame([$user->id, $user->id], JournalEntry::query()->orderBy('id')->pluck('posted_by')->all());
    }

    public function test_system_postings_have_no_user(): void
    {
        $entry = app(LedgerService::class)->post(new NewJournalEntry(today(), 'Opening balance', [
            EntryLine::debit($this->account('bank'), Money::parse('100.00')),
            EntryLine::credit($this->accountByCode('3000'), Money::parse('100.00')),
        ]));

        $this->assertNull($entry->posted_by);
    }

    public function test_account_changes_are_logged_with_old_and_new_values(): void
    {
        $user = User::factory()->role(UserRole::Admin)->create();
        Sanctum::actingAs($user, TokenAbility::all());
        $account = $this->accountByCode('6000');

        $this->patchJson("/api/v1/accounts/{$account->id}", ['name' => 'Office & supplies', 'is_active' => false])->assertOk();

        $log = ActivityLog::query()->where('event', 'updated')->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('account', $log->subject_type);
        $this->assertSame($account->id, $log->subject_id);
        // assertEquals: MySQL's JSON type doesn't keep object key order.
        $this->assertEquals(['old' => 'Office expenses', 'new' => 'Office & supplies'], $log->changes['name']);
        $this->assertEquals(['old' => 1, 'new' => 0], $log->changes['is_active']);
        $this->assertArrayNotHasKey('updated_at', $log->changes);
    }

    public function test_an_update_that_changes_nothing_is_not_logged(): void
    {
        $this->actingAsApiUser();
        $customer = Customer::factory()->create(['name' => 'Acme']);

        $this->patchJson("/api/v1/customers/{$customer->id}", ['name' => 'Acme'])->assertOk();

        $this->assertSame(0, ActivityLog::query()->where('event', 'updated')->count());
    }

    public function test_customers_created_in_the_admin_panel_are_logged(): void
    {
        $user = User::factory()->role(UserRole::Accountant)->create();
        $this->actingAs($user);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Caspian Trading', 'email' => 'billing@caspian.test'])
            ->call('create')
            ->assertHasNoFormErrors();

        $log = ActivityLog::query()->where('subject_type', 'customer')->sole();
        $this->assertSame('created', $log->event);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('Caspian Trading', $log->changes['name']);
    }

    public function test_the_activity_log_cannot_be_changed(): void
    {
        $log = ActivityLog::record('created', Customer::factory()->create());

        $this->assertThrows(fn () => $log->update(['event' => 'tampered']), LedgerException::class);
        $this->assertThrows(fn () => $log->delete(), LedgerException::class);
    }

    public function test_every_role_can_read_the_activity_log_in_the_admin_panel(): void
    {
        Customer::factory()->create(['name' => 'Acme']);
        $this->actingAs(User::factory()->role(UserRole::Viewer)->create());

        $this->get(ActivityLogResource::getUrl('index'))->assertOk();
        Livewire::test(ListActivityLogs::class)->assertOk()->assertSee('Customer #');
    }
}
