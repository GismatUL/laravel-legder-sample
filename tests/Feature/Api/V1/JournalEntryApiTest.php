<?php

namespace Tests\Feature\Api\V1;

class JournalEntryApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
    }

    /**
     * @return array<string, mixed>
     */
    private function officeSupplies(string $debit = '50.00', string $credit = '50.00'): array
    {
        return [
            'entry_date' => today()->toDateString(),
            'description' => 'Office supplies',
            'lines' => [
                ['account_id' => $this->accountByCode('6000')->id, 'debit' => $debit],
                ['account_id' => $this->account('bank')->id, 'credit' => $credit, 'memo' => 'Card payment'],
            ],
        ];
    }

    public function test_posts_a_balanced_manual_entry(): void
    {
        $response = $this->postJson('/api/v1/journal-entries', $this->officeSupplies())
            ->assertCreated()
            ->assertJsonPath('data.source', null)
            ->assertJsonPath('data.total.amount', '50.00')
            ->assertJsonPath('data.lines.0.account.code', '6000')
            ->assertJsonPath('data.lines.0.debit.amount', '50.00')
            ->assertJsonPath('data.lines.1.credit.amount', '50.00');

        $this->assertMatchesRegularExpression('/^JE-\d{6}$/', $response->json('data.reference'));
    }

    public function test_an_unbalanced_entry_is_rejected_and_nothing_is_written(): void
    {
        $this->postJson('/api/v1/journal-entries', $this->officeSupplies('50.00', '45.00'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'ledger.unbalanced');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_a_line_needs_a_debit_or_a_credit(): void
    {
        $payload = $this->officeSupplies();
        unset($payload['lines'][0]['debit']);

        $this->postJson('/api/v1/journal-entries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.debit', 'lines.0.credit']);
    }

    public function test_idempotency_key_prevents_double_posting(): void
    {
        $first = $this->withHeader('Idempotency-Key', 'import-42')
            ->postJson('/api/v1/journal-entries', $this->officeSupplies())
            ->assertCreated()
            ->json('data.id');

        $this->withHeader('Idempotency-Key', 'import-42')
            ->postJson('/api/v1/journal-entries', $this->officeSupplies())
            ->assertOk()
            ->assertJsonPath('data.id', $first);

        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_reverses_a_manual_entry_once(): void
    {
        $id = $this->postJson('/api/v1/journal-entries', $this->officeSupplies())->json('data.id');

        $reversalId = $this->postJson("/api/v1/journal-entries/{$id}/reversal")
            ->assertCreated()
            ->assertJsonPath('data.reversal_of_id', $id)
            ->assertJsonPath('data.lines.0.credit.amount', '50.00')
            ->json('data.id');

        $this->postJson("/api/v1/journal-entries/{$id}/reversal")
            ->assertConflict()
            ->assertJsonPath('code', 'ledger.already_reversed');

        $this->getJson("/api/v1/journal-entries/{$id}")->assertJsonPath('data.reversed_by_id', $reversalId);
        $this->assertTrue($this->balance('bank')->isZero());
    }

    public function test_document_entries_cannot_be_reversed_directly(): void
    {
        $invoice = $this->issuedInvoice();

        $this->getJson("/api/v1/journal-entries/{$invoice->journal_entry_id}")
            ->assertJsonPath('data.source', ['type' => 'invoice', 'id' => $invoice->id]);

        $this->postJson("/api/v1/journal-entries/{$invoice->journal_entry_id}/reversal")
            ->assertConflict()
            ->assertJsonPath('code', 'ledger.document_entry');
    }

    public function test_filters_entries_by_account_and_date(): void
    {
        $this->postJson('/api/v1/journal-entries', $this->officeSupplies());
        $this->issuedInvoice();

        $this->getJson('/api/v1/journal-entries?account_id='.$this->accountByCode('6000')->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/journal-entries?from='.today()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
