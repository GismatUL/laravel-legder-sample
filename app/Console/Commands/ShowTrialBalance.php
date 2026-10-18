<?php

namespace App\Console\Commands;

use App\Reports\TrialBalance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ShowTrialBalance extends Command
{
    protected $signature = 'ledger:trial-balance {--as-of= : Include entries up to this date (Y-m-d)}';

    protected $description = 'Print the trial balance and check that debits equal credits';

    public function handle(): int
    {
        $report = new TrialBalance($this->option('as-of') ? Carbon::parse($this->option('as-of')) : null);

        $this->table(
            ['Code', 'Account', 'Debit', 'Credit'],
            $report->rows->map(fn ($row) => [
                $row['code'],
                $row['name'],
                $row['debit']->isZero() ? '' : $row['debit']->format(),
                $row['credit']->isZero() ? '' : $row['credit']->format(),
            ])->push(['', 'TOTAL', $report->totalDebit->format(), $report->totalCredit->format()]),
        );

        if (! $report->isBalanced()) {
            $this->error('The ledger is out of balance.');

            return self::FAILURE;
        }

        $this->info('Debits equal credits.');

        return self::SUCCESS;
    }
}
