<?php

namespace Tests\Feature;

use App\Data\EntryLine;
use App\Data\ManualEntryLineData;
use App\Data\NewJournalEntry;
use App\Exceptions\LedgerException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $this->assertTrue($this->balance('bank')->equals(Money::parse('150.00')));
        $this->assertTrue($this->balance('sales')->equals(Money::parse('150.00')));
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
        $this->assertTrue($this->balance('bank')->isZero());
        $this->assertTrue($this->balance('sales')->isZero());
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_an_entry_can_only_be_reversed_once(): void
    {
        $entry = $this->ledger->post($this->cashSale('500.00'));
        $this->ledger->reverse($entry, today());

        $this->expectException(LedgerException::class);

        $this->ledger->reverse($entry, today());
    }

    /** @return array<string, array{int, int}> */
    public static function invalidLineAmounts(): array
    {
        return [
            'zero on both sides' => [0, 0],
            'negative debit' => [-100, 0],
            'negative credit' => [0, -100],
        ];
    }

    #[DataProvider('invalidLineAmounts')]
    public function test_rejects_lines_without_exactly_one_positive_side(int $debit, int $credit): void
    {
        try {
            $this->ledger->post(new NewJournalEntry(today(), 'Broken', [
                new EntryLine($this->account('bank'), debit: $debit, credit: $credit),
                new EntryLine($this->account('sales'), debit: $credit, credit: $debit),
            ]));
            $this->fail('Invalid line was accepted.');
        } catch (LedgerException $e) {
            $this->assertSame('ledger.invalid_line', $e->errorCode);
        }

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_rejects_an_entry_without_lines(): void
    {
        $this->expectExceptionObject(LedgerException::tooFewLines());

        $this->ledger->post(new NewJournalEntry(today(), 'Empty', []));
    }

    public function test_balances_across_more_than_two_lines(): void
    {
        $entry = $this->ledger->post(new NewJournalEntry(today(), 'Split sale', [
            EntryLine::debit($this->account('bank'), Money::parse('118.00')),
            EntryLine::credit($this->account('sales'), Money::parse('100.00')),
            EntryLine::credit($this->account('vat_payable'), Money::parse('18.00')),
        ]));

        $this->assertTrue($entry->totalDebits()->equals($entry->totalCredits()));
    }

    /**
     * @return array<string, array{array{date?: string, description?: string, memo?: string}}>
     */
    public static function changedEntries(): array
    {
        return [
            'different date' => [['date' => 'yesterday']],
            'different description' => [['description' => 'Another sale']],
            'different memo' => [['memo' => 'changed']],
        ];
    }

    /**
     * Regression: only account/debit/credit used to be compared, so a retry
     * with another date or description silently returned the first entry.
     *
     * @param  array{date?: string, description?: string, memo?: string}  $change
     */
    #[DataProvider('changedEntries')]
    public function test_manual_entry_key_reuse_with_any_field_changed_is_a_conflict(array $change): void
    {
        $post = fn (array $change = []) => $this->ledger->postManual(
            isset($change['date']) ? today()->subDay() : today(),
            $change['description'] ?? 'Office supplies',
            [
                new ManualEntryLineData($this->accountByCode('6000')->id, Money::parse('20.00'), Money::zero(), $change['memo'] ?? null),
                new ManualEntryLineData($this->account('bank')->id, Money::zero(), Money::parse('20.00')),
            ],
            'client-key-1',
        );

        $post();

        $this->expectExceptionObject(LedgerException::idempotencyConflict('manual:client-key-1'));

        $post($change);
    }

    public function test_manual_entry_replay_ignores_line_order(): void
    {
        $expense = new ManualEntryLineData($this->accountByCode('6000')->id, Money::parse('20.00'), Money::zero());
        $bank = new ManualEntryLineData($this->account('bank')->id, Money::zero(), Money::parse('20.00'));

        $first = $this->ledger->postManual(today(), 'Office supplies', [$expense, $bank], 'k-1');
        $retry = $this->ledger->postManual(today(), 'Office supplies', [$bank, $expense], 'k-1');

        $this->assertTrue($first->is($retry));
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_manual_entries_cannot_post_to_receivables(): void
    {
        // Receivables must always equal the open invoices.
        try {
            $this->ledger->postManual(today(), 'Write-off', [
                new ManualEntryLineData($this->accountByCode('6000')->id, Money::parse('10.00'), Money::zero()),
                new ManualEntryLineData($this->account('receivable')->id, Money::zero(), Money::parse('10.00')),
            ]);
            $this->fail('Manual posting to receivables was accepted.');
        } catch (LedgerException $e) {
            $this->assertSame('ledger.control_account', $e->errorCode);
        }

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_manual_entry_with_an_unknown_account_is_rejected(): void
    {
        $this->expectExceptionObject(LedgerException::unknownAccount(999999));

        $this->ledger->postManual(today(), 'Typo', [
            new ManualEntryLineData(999999, Money::parse('10.00'), Money::zero()),
            new ManualEntryLineData($this->account('bank')->id, Money::zero(), Money::parse('10.00')),
        ]);
    }

    public function test_a_reversal_cannot_be_reversed(): void
    {
        $entry = $this->ledger->postManual(today(), 'Office supplies', [
            new ManualEntryLineData($this->accountByCode('6000')->id, Money::parse('20.00'), Money::zero()),
            new ManualEntryLineData($this->account('bank')->id, Money::zero(), Money::parse('20.00')),
        ]);
        $reversal = $this->ledger->reverseManual($entry, today());

        $this->expectExceptionObject(LedgerException::reversalOfReversal($reversal->reference));

        $this->ledger->reverseManual($reversal, today());
    }

    public function test_trial_balance_includes_the_as_of_day_and_excludes_the_next(): void
    {
        $this->ledger->post(new NewJournalEntry(Carbon::parse('2026-03-31'), 'Quarter end', [
            EntryLine::debit($this->account('bank'), Money::parse('10.00')),
            EntryLine::credit($this->account('sales'), Money::parse('10.00')),
        ]));
        $this->ledger->post(new NewJournalEntry(Carbon::parse('2026-04-01'), 'Next quarter', [
            EntryLine::debit($this->account('bank'), Money::parse('5.00')),
            EntryLine::credit($this->account('sales'), Money::parse('5.00')),
        ]));

        $reports = app(ReportService::class);

        $this->assertSame(1000, $reports->trialBalance(Carbon::parse('2026-03-31'))->totalDebit->minor);
        $this->assertSame(1500, $reports->trialBalance(Carbon::parse('2026-04-01'))->totalDebit->minor);
        $this->assertTrue($reports->trialBalance(Carbon::parse('2026-03-30'))->rows->isEmpty());
    }

    public function test_trial_balance_balances_and_respects_the_as_of_date(): void
    {
        $this->ledger->post(new NewJournalEntry(today()->subMonth(), 'Old sale', [
            EntryLine::debit($this->account('bank'), Money::parse('100.00')),
            EntryLine::credit($this->account('sales'), Money::parse('100.00')),
        ]));
        $this->ledger->post($this->cashSale('40.00'));

        $now = app(ReportService::class)->trialBalance();
        $lastWeek = app(ReportService::class)->trialBalance(today()->subWeek());

        $this->assertTrue($now->isBalanced());
        $this->assertTrue($now->totalDebit->equals(Money::parse('140.00')));
        $this->assertTrue($lastWeek->totalDebit->equals(Money::parse('100.00')));
    }
}
