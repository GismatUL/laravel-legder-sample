<?php

namespace App\Reports;

use App\Enums\AccountType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trial balance: every account's net balance, shown in the debit or credit
 * column. In a correct double-entry ledger the two columns are always equal.
 * The totals are calculated in SQL, so the report stays fast on large ledgers.
 */
class TrialBalance
{
    /** @var Collection<int, array{code: string, name: string, type: AccountType, debit: Money, credit: Money}> */
    public readonly Collection $rows;

    public readonly Money $totalDebit;

    public readonly Money $totalCredit;

    public function __construct(public readonly ?CarbonInterface $asOf = null)
    {
        $this->rows = DB::table('accounts')
            ->join('journal_lines', 'journal_lines.account_id', '=', 'accounts.id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($asOf, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $asOf))
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.code, accounts.name, accounts.type, SUM(journal_lines.debit) - SUM(journal_lines.credit) AS net')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->code,
                'name' => $row->name,
                'type' => AccountType::from($row->type),
                'debit' => Money::of(max((int) $row->net, 0)),
                'credit' => Money::of(max(-(int) $row->net, 0)),
            ]);

        $this->totalDebit = $this->rows->reduce(fn (Money $sum, $row) => $sum->add($row['debit']), Money::zero());
        $this->totalCredit = $this->rows->reduce(fn (Money $sum, $row) => $sum->add($row['credit']), Money::zero());
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit->equals($this->totalCredit);
    }
}
