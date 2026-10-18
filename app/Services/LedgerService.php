<?php

namespace App\Services;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Exceptions\LedgerException;
use App\Models\JournalEntry;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way to write to the general ledger.
 *
 * Every entry is validated as a whole (balanced, at least two lines,
 * one side per line) and written in a single transaction, so the ledger
 * can never contain half an entry.
 */
class LedgerService
{
    public function post(NewJournalEntry $entry): JournalEntry
    {
        $this->validate($entry);

        if ($entry->idempotencyKey !== null && $existing = $this->findByKey($entry->idempotencyKey)) {
            return $this->assertSameEntry($existing, $entry);
        }

        try {
            return DB::transaction(fn () => $this->write($entry));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key committed first.
            $existing = $entry->idempotencyKey !== null ? $this->findByKey($entry->idempotencyKey) : null;

            return $existing ? $this->assertSameEntry($existing, $entry) : throw $e;
        }
    }

    public function reverse(JournalEntry $original, CarbonInterface $date, ?string $description = null): JournalEntry
    {
        return DB::transaction(function () use ($original, $date, $description) {
            // Lock the original so two users can't reverse it at the same time.
            $original = JournalEntry::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();

            if ($original->reversedBy()->exists()) {
                throw LedgerException::alreadyReversed($original->reference);
            }

            $lines = $original->lines()->with('account')->get()
                ->map(fn ($line) => new EntryLine($line->account, debit: $line->credit, credit: $line->debit, memo: $line->memo))
                ->all();

            $reversal = new NewJournalEntry(
                date: $date,
                description: $description ?? "Reversal of {$original->reference}",
                lines: $lines,
                idempotencyKey: "reversal:{$original->getKey()}",
            );

            $this->validate($reversal);

            return $this->write($reversal, reversalOf: $original);
        });
    }

    private function write(NewJournalEntry $entry, ?JournalEntry $reversalOf = null): JournalEntry
    {
        $journalEntry = new JournalEntry([
            'entry_date' => $entry->date,
            'description' => $entry->description,
            'idempotency_key' => $entry->idempotencyKey,
            'reversal_of_id' => $reversalOf?->getKey(),
            'posted_at' => now(),
        ]);

        if ($entry->source) {
            $journalEntry->source()->associate($entry->source);
        }

        $journalEntry->save();

        $journalEntry->lines()->createMany(array_map(fn (EntryLine $line) => [
            'account_id' => $line->account->getKey(),
            'debit' => $line->debit,
            'credit' => $line->credit,
            'memo' => $line->memo,
        ], $entry->lines));

        return $journalEntry->load('lines.account');
    }

    private function validate(NewJournalEntry $entry): void
    {
        if (count($entry->lines) < 2) {
            throw LedgerException::tooFewLines();
        }

        $debits = 0;
        $credits = 0;

        foreach ($entry->lines as $line) {
            $oneSided = ($line->debit > 0 && $line->credit === 0) || ($line->credit > 0 && $line->debit === 0);

            if (! $oneSided || $line->debit < 0 || $line->credit < 0) {
                throw LedgerException::invalidLine($line->account->code);
            }

            if (! $line->account->is_active) {
                throw LedgerException::inactiveAccount($line->account->code);
            }

            $debits += $line->debit;
            $credits += $line->credit;
        }

        if ($debits !== $credits) {
            throw LedgerException::unbalanced(Money::of($debits), Money::of($credits));
        }
    }

    private function findByKey(string $key): ?JournalEntry
    {
        return JournalEntry::query()->with('lines.account')->where('idempotency_key', $key)->first();
    }

    /**
     * A retried request must describe the same posting. Reusing a key for
     * different amounts is a bug in the caller, so fail loudly.
     */
    private function assertSameEntry(JournalEntry $existing, NewJournalEntry $entry): JournalEntry
    {
        $signature = fn (iterable $lines) => collect($lines)
            ->map(fn ($line) => "{$line->account->code}:{$line->debit}:{$line->credit}")
            ->sort()->values()->all();

        if ($signature($existing->lines) !== $signature($entry->lines)) {
            throw LedgerException::idempotencyConflict($entry->idempotencyKey);
        }

        return $existing;
    }
}
