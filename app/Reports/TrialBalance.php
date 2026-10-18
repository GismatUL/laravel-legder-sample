<?php

namespace App\Reports;

use App\Data\AccountTotals;
use App\Enums\AccountType;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Trial balance: every account's net balance, shown in the debit or credit
 * column. In a correct double-entry ledger the two columns are always equal.
 */
final class TrialBalance
{
    /** @var Collection<int, array{code: string, name: string, type: AccountType, debit: Money, credit: Money}> */
    public readonly Collection $rows;

    public readonly Money $totalDebit;

    public readonly Money $totalCredit;

    /**
     * @param  Collection<int, AccountTotals>  $accountTotals
     */
    public function __construct(Collection $accountTotals, public readonly ?CarbonInterface $asOf = null)
    {
        $this->rows = $accountTotals->map(function (AccountTotals $totals) {
            $net = $totals->debits - $totals->credits;

            return [
                'code' => $totals->code,
                'name' => $totals->name,
                'type' => $totals->type,
                'debit' => Money::of(max($net, 0)),
                'credit' => Money::of(max(-$net, 0)),
            ];
        });

        $this->totalDebit = $this->rows->reduce(fn (Money $sum, array $row) => $sum->add($row['debit']), Money::zero());
        $this->totalCredit = $this->rows->reduce(fn (Money $sum, array $row) => $sum->add($row['credit']), Money::zero());
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit->equals($this->totalCredit);
    }
}
