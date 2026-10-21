<?php

namespace App\Services;

use App\Data\EntryLine;
use App\Data\ManualEntryLineData;
use App\Data\NewJournalEntry;
use App\Events\LedgerPosted;
use App\Exceptions\LedgerException;
use App\Models\JournalEntry;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
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
    /**
     * Top-level financial transactions are retried when MySQL picks them as
     * a deadlock victim. Everything inside rolled back, so a retry is safe.
     * Laravel only retries the outermost transaction; nested calls rethrow.
     */
    public const DEADLOCK_ATTEMPTS = 3;

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
        $controlCodes = array_map(fn (string $key) => (string) config("ledger.accounts.{$key}"), (array) config('ledger.control_accounts'));

        return $this->post(new NewJournalEntry(
            date: $date,
            description: $description,
            lines: array_map(function (ManualEntryLineData $line) use ($accounts, $controlCodes) {
                $account = $accounts->get($line->accountId) ?? throw LedgerException::unknownAccount($line->accountId);

                // Receivables are the sum of open invoices. A manual posting there
                // would make the ledger and the invoices disagree.
                if (in_array($account->code, $controlCodes, true)) {
                    throw LedgerException::controlAccount($account->code);
                }

                return new EntryLine($account, debit: $line->debit->minor, credit: $line->credit->minor, memo: $line->memo);
            }, $lines),
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
            return DB::transaction(fn () => $this->write($entry), attempts: self::DEADLOCK_ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key committed first. A locking
            // read sees it even inside an outer REPEATABLE READ transaction,
            // where a plain read would still return the older snapshot.
            $existing = $entry->idempotencyKey !== null ? $this->entries->findByIdempotencyKey($entry->idempotencyKey, lock: true) : null;

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
        }, attempts: self::DEADLOCK_ATTEMPTS);
    }

    private function write(NewJournalEntry $entry, ?JournalEntry $reversalOf = null): JournalEntry
    {
        $written = $this->entries->create([
            'entry_date' => $entry->date,
            'description' => $entry->description,
            'idempotency_key' => $entry->idempotencyKey,
            'reversal_of_id' => $reversalOf?->getKey(),
            'posted_at' => now(),
            'posted_by' => Auth::id(),
        ], $entry->lines, $entry->source);

        // Held until the outermost transaction commits (ShouldDispatchAfterCommit),
        // and dropped if it rolls back.
        LedgerPosted::dispatch($written->getKey(), $entry->date->toDateString());

        return $written;
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
     * A retried request must describe the same posting: same date, description
     * and lines. Lines are compared as a sorted list, so the order a client
     * sends them in doesn't matter. Reusing a key for a different posting is
     * a bug in the caller, so fail loudly.
     */
    private function assertSameEntry(JournalEntry $existing, NewJournalEntry $entry): JournalEntry
    {
        $lines = fn (iterable $lines) => collect($lines)
            ->map(fn ($line) => [(int) $line->account->getKey(), (int) $line->debit, (int) $line->credit, (string) $line->memo])
            ->sort()->values()->all();

        $same = $existing->entry_date->toDateString() === $entry->date->toDateString()
            && $existing->description === $entry->description
            && $lines($existing->lines) === $lines($entry->lines);

        if (! $same) {
            throw LedgerException::idempotencyConflict((string) $entry->idempotencyKey);
        }

        return $existing;
    }
}
