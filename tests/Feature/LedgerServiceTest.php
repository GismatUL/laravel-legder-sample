<?php

namespace Tests\Feature;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Exceptions\LedgerException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Reports\TrialBalance;
use App\Services\LedgerService;
use App\Support\Money;

class LedgerServiceTest extends LedgerTestCase
{
    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
    }

    private function cashSale(string $amount, ?string $key = null): NewJournalEntry
    {
        return new NewJournalEntry(today(), 'Cash sale', [
            EntryLine::debit($this->account('bank'), Money::parse($amount)),
            EntryLine::credit($this->account('sales'), Money::parse($amount)),
        ], $key);
    }

    public function test_posts_a_balanced_entry(): void
    {
        $entry = $this->ledger->post($this->cashSale('150.00'));

        $this->assertCount(2, $entry->lines);
        $this->assertTrue($entry->totalDebits()->equals(Money::parse('150.00')));
        $this->assertTrue($this->account('bank')->balance()->equals(Money::parse('150.00')));
        $this->assertTrue($this->account('sales')->balance()->equals(Money::parse('150.00')));
    }

    public function test_rejects_an_unbalanced_entry_and_writes_nothing(): void
    {
        try {
            $this->ledger->post(new NewJournalEntry(today(), 'Broken', [
                EntryLine::debit($this->account('bank'), Money::parse('100.00')),
                EntryLine::credit($this->account('sales'), Money::parse('99.99')),
            ]));
            $this->fail('Unbalanced entry was accepted.');
        } catch (LedgerException $e) {
            $this->assertStringContainsString('unbalanced', $e->getMessage());
        }

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_rejects_a_line_with_both_debit_and_credit(): void
    {
        $this->expectException(LedgerException::class);

        $this->ledger->post(new NewJournalEntry(today(), 'Broken', [
            new EntryLine($this->account('bank'), debit: 100, credit: 100),
            EntryLine::credit($this->account('sales'), Money::of(0)),
        ]));
    }

    public function test_rejects_a_single_line_entry(): void
    {
        $this->expectException(LedgerException::class);

        $this->ledger->post(new NewJournalEntry(today(), 'Broken', [
            EntryLine::debit($this->account('bank'), Money::of(100)),
        ]));
    }

    public function test_rejects_posting_to_an_inactive_account(): void
    {
        $this->account('sales')->update(['is_active' => false]);

        $this->expectException(LedgerException::class);

        $this->ledger->post($this->cashSale('10.00'));
    }

    public function test_same_idempotency_key_posts_only_once(): void
    {
        $first = $this->ledger->post($this->cashSale('80.00', 'order-42'));
        $retry = $this->ledger->post($this->cashSale('80.00', 'order-42'));

        $this->assertTrue($first->is($retry));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_reusing_a_key_for_a_different_amount_fails(): void
    {
        $this->ledger->post($this->cashSale('80.00', 'order-42'));

        $this->expectException(LedgerException::class);

        $this->ledger->post($this->cashSale('81.00', 'order-42'));
    }

    public function test_posted_entries_and_lines_cannot_be_changed_or_deleted(): void
    {
        $entry = $this->ledger->post($this->cashSale('10.00'));

        $this->assertThrows(fn () => $entry->update(['description' => 'Edited']), LedgerException::class);
        $this->assertThrows(fn () => $entry->delete(), LedgerException::class);
        $this->assertThrows(fn () => JournalLine::first()->update(['debit' => 1]), LedgerException::class);
    }

    public function test_reversal_cancels_the_original_and_keeps_history(): void
    {
        $entry = $this->ledger->post($this->cashSale('500.00'));

        $reversal = $this->ledger->reverse($entry, today());

        $this->assertTrue($reversal->reversalOf->is($entry));
        $this->assertTrue($this->account('bank')->balance()->isZero());
        $this->assertTrue($this->account('sales')->balance()->isZero());
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_an_entry_can_only_be_reversed_once(): void
    {
        $entry = $this->ledger->post($this->cashSale('500.00'));
        $this->ledger->reverse($entry, today());

        $this->expectException(LedgerException::class);

        $this->ledger->reverse($entry, today());
    }

    public function test_trial_balance_balances_and_respects_the_as_of_date(): void
    {
        $this->ledger->post(new NewJournalEntry(today()->subMonth(), 'Old sale', [
            EntryLine::debit($this->account('bank'), Money::parse('100.00')),
            EntryLine::credit($this->account('sales'), Money::parse('100.00')),
        ]));
        $this->ledger->post($this->cashSale('40.00'));

        $now = new TrialBalance;
        $lastWeek = new TrialBalance(today()->subWeek());

        $this->assertTrue($now->isBalanced());
        $this->assertTrue($now->totalDebit->equals(Money::parse('140.00')));
        $this->assertTrue($lastWeek->totalDebit->equals(Money::parse('100.00')));
    }
}
