<?php

namespace App\Repositories\Contracts;

use App\Data\AccountTotals;
use App\Data\EntryLine;
use App\Models\JournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface JournalEntryRepositoryInterface
{
    /**
     * With $lock, a locking read (FOR UPDATE) that sees rows committed after
     * the current transaction's snapshot was taken.
     */
    public function findByIdempotencyKey(string $key, bool $lock = false): ?JournalEntry;

    public function findForUpdate(int $id): JournalEntry;

    public function isReversed(JournalEntry $entry): bool;

    public function loadDetails(JournalEntry $entry): JournalEntry;

    public function findWithDetails(int $id): JournalEntry;

    /**
     * @return LengthAwarePaginator<int, JournalEntry>
     */
    public function paginate(?CarbonInterface $from, ?CarbonInterface $to, ?int $accountId, int $perPage): LengthAwarePaginator;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, EntryLine>  $lines
     */
    public function create(array $attributes, array $lines, ?Model $source = null): JournalEntry;

    /**
     * Net debit/credit totals per account, for entries dated on or before $asOf.
     *
     * @return Collection<int, AccountTotals>
     */
    public function totalsByAccount(?CarbonInterface $asOf): Collection;
}
