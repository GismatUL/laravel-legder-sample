<?php

namespace App\Repositories\Eloquent;

use App\Data\AccountTotals;
use App\Data\EntryLine;
use App\Enums\AccountType;
use App\Exceptions\MoneyException;
use App\Models\JournalEntry;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentJournalEntryRepository implements JournalEntryRepositoryInterface
{
    public function findByIdempotencyKey(string $key, bool $lock = false): ?JournalEntry
    {
        return JournalEntry::query()
            ->with('lines.account')
            ->where('idempotency_key', $key)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    public function findForUpdate(int $id): JournalEntry
    {
        return JournalEntry::query()->with('lines.account')->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function isReversed(JournalEntry $entry): bool
    {
        return $entry->reversedBy()->exists();
    }

    public function loadDetails(JournalEntry $entry): JournalEntry
    {
        return $entry->load(['lines.account', 'reversalOf', 'reversedBy', 'postedBy']);
    }

    public function findWithDetails(int $id): JournalEntry
    {
        return $this->loadDetails(JournalEntry::query()->findOrFail($id));
    }

    public function paginate(?CarbonInterface $from, ?CarbonInterface $to, ?int $accountId, int $perPage): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->with(['lines.account', 'postedBy'])
            ->when($from, fn ($query) => $query->where('entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->where('entry_date', '<', $to->copy()->addDay()->toDateString()))
            ->when($accountId, fn ($query) => $query->whereHas('lines', fn ($q) => $q->where('account_id', $accountId)))
            ->latest('id')
            ->paginate($perPage);
    }

    public function create(array $attributes, array $lines, ?Model $source = null): JournalEntry
    {
        $entry = new JournalEntry($attributes);

        if ($source) {
            $entry->source()->associate($source);
        }

        $entry->save();

        $entry->lines()->createMany(array_map(fn (EntryLine $line) => [
            'account_id' => $line->account->getKey(),
            'debit' => $line->debit,
            'credit' => $line->credit,
            'memo' => $line->memo,
        ], $lines));

        return $entry->load('lines.account');
    }

    public function totalsByAccount(?CarbonInterface $asOf): Collection
    {
        return DB::table('accounts')
            ->join('journal_lines', 'journal_lines.account_id', '=', 'accounts.id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            // "< next day" instead of whereDate(): DATE(col) can't use the entry_date index.
            ->when($asOf, fn ($query) => $query->where('journal_entries.entry_date', '<', $asOf->copy()->addDay()->toDateString()))
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.code, accounts.name, accounts.type, SUM(journal_lines.debit) AS debits, SUM(journal_lines.credit) AS credits')
            ->get()
            ->map(fn (object $row) => new AccountTotals(
                code: (string) $row->code,
                name: (string) $row->name,
                type: AccountType::from((string) $row->type),
                debits: $this->toInt($row->debits),
                credits: $this->toInt($row->credits),
            ));
    }

    /**
     * MySQL returns SUM() as a DECIMAL string; (int) would silently saturate
     * a total beyond PHP_INT_MAX instead of failing.
     */
    private function toInt(mixed $sum): int
    {
        $value = filter_var($sum, FILTER_VALIDATE_INT);

        return $value === false ? throw MoneyException::overflow() : $value;
    }
}
