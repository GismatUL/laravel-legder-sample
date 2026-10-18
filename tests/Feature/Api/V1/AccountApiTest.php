<?php

namespace Tests\Feature\Api\V1;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Services\LedgerService;
use App\Support\Money;

class AccountApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
    }

    public function test_lists_accounts_with_balances_in_the_normal_direction(): void
    {
        app(LedgerService::class)->post(new NewJournalEntry(today(), 'Cash sale', [
            EntryLine::debit($this->account('bank'), Money::parse('250.00')),
            EntryLine::credit($this->account('sales'), Money::parse('250.00')),
        ]));

        $response = $this->getJson('/api/v1/accounts?type=asset')->assertOk();

        $bank = $response->collect('data')->firstWhere('code', '1000');
        $this->assertSame(['amount' => '250.00', 'currency' => 'AZN'], $bank['balance']);
        $this->assertSame(['asset'], $response->collect('data')->pluck('type')->unique()->values()->all());

        // Revenue has a credit-normal balance, so it's positive too.
        $this->getJson('/api/v1/accounts/'.$this->account('sales')->id)
            ->assertOk()
            ->assertJsonPath('data.balance.amount', '250.00');
    }

    public function test_creates_an_account(): void
    {
        $this->postJson('/api/v1/accounts', ['code' => '6100', 'name' => 'Rent', 'type' => 'expense'])
            ->assertCreated()
            ->assertJsonPath('data.code', '6100')
            ->assertJsonPath('data.type', 'expense');
    }

    public function test_validates_account_input(): void
    {
        $this->postJson('/api/v1/accounts', ['code' => '1000', 'name' => '', 'type' => 'spaceship'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'type']);
    }

    public function test_code_and_type_are_locked_once_the_account_has_postings(): void
    {
        app(LedgerService::class)->post(new NewJournalEntry(today(), 'Cash sale', [
            EntryLine::debit($this->account('bank'), Money::parse('1.00')),
            EntryLine::credit($this->account('sales'), Money::parse('1.00')),
        ]));

        $id = $this->account('bank')->id;

        $this->patchJson("/api/v1/accounts/{$id}", ['type' => 'expense'])
            ->assertConflict()
            ->assertJsonPath('code', 'ledger.account_has_postings');

        $this->patchJson("/api/v1/accounts/{$id}", ['name' => 'Main bank account'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Main bank account');
    }

    public function test_unknown_account_returns_404_without_internals(): void
    {
        $this->getJson('/api/v1/accounts/99999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.', 'code' => 'not_found']);
    }
}
