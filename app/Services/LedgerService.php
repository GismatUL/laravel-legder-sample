<?php

namespace App\Services;

use App\Data\EntryLine;
use App\Data\ManualEntryLineData;
use App\Data\NewJournalEntry;
use App\Exceptions\LedgerException;
use App\Models\JournalEntry;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
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
    public function __construct(
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly AccountRepositoryInterface $accounts,
    ) {}

    /**
     * A manual entry entered by a person (admin panel or API), e.g. an
     * expense paid from the bank. Goes through the same checks as any posting.
     *
     * @param  list<ManualEntryLineData>  $lines
     */
    public function postManual(CarbonInterface $date, string $description, array $lines, ?string $idempotencyKey = null): JournalEntry
    {
        $accounts = $this->accounts->findManyKeyedById(array_map(fn (ManualEntryLineData $line) => $line->accountId, $lines));

        return $this->post(new NewJournalEntry(
            date: $date,
            description: $description,
            lines: array_map(fn (ManualEntryLineData $line) => new EntryLine(
                $accounts->get($line->accountId) ?? throw LedgerException::unknownAccount($line->accountId),
                debit: $line->debit->minor,
                credit: $line->credit->minor,
                memo: $line->memo,
            ), $lines),
            idempotencyKey: $idempotencyKey === null ? null : "manual:{$idempotencyKey}",
        ));
    }

    public function post(NewJournalEntry $entry): JournalEntry
    {
        $this->validate($entry);

        if ($entry->idempotencyKey !== null && $existing = $this->entries->findByIdempotencyKey($entry->idempotencyKey)) {
            return $this->assertSameEntry($existing, $entry);
        }

        try {
            return DB::transaction(fn () => $this->write($entry));
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key committed first.
            $existing = $entry->idempotencyKey !== null ? $this->entries->findByIdempotencyKey($entry->idempotencyKey) : null;

            return $existing ? $this->assertSameEntry($existing, $entry) : throw $e;
        }
    }

    /**
     * Reverse an entry a person posted by hand. Entries created by documents
     * (invoices, payments) are reversed through their own workflow, e.g.
     * voiding the invoice, so the document and the ledger stay in sync.
     */
    public function reverseManual(JournalEntry $original, CarbonInterface $date): JournalEntry
    {
        if ($original->source_type !== null) {
            throw LedgerException::documentEntry($original->reference);
        }

        if ($original->reversal_of_id !== null) {
            throw LedgerException::reversalOfReversal($original->reference);
        }

        return $this->reverse($original, $date);
    }

    public function reverse(JournalEntry $original, CarbonInterface $date, ?string $description = null): JournalEntry
    {
        return DB::transaction(function () use ($original, $date, $description) {
            // Lock the original so two users can't reverse it at the same time.
            $original = $this->entries->findForUpdate($original->getKey());

            if ($this->entries->isReversed($original)) {
                throw LedgerException::alreadyReversed($original->reference);
            }

            $lines = $original->lines
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
        return $this->entries->create([
            'entry_date' => $entry->date,
            'description' => $entry->description,
            'idempotency_key' => $entry->idempotencyKey,
            'reversal_of_id' => $reversalOf?->getKey(),
            'posted_at' => now(),
        ], $entry->lines, $entry->source);
    }

    private function validate(NewJournalEntry $entry): void
    {
        if (count($entry->lines) < 2) {
            throw LedgerException::tooFewLines();
        }

        $debits = 0;
        $credits = 0;

        foreach ($entry->lines as $line) {
            // Exactly one side positive, the other zero. Negative amounts fail this too.
            $oneSided = ($line->debit > 0 && $line->credit === 0) || ($line->credit > 0 && $line->debit === 0);

            if (! $oneSided) {
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
            throw LedgerException::idempotencyConflict((string) $entry->idempotencyKey);
        }

        return $existing;
    }
}
