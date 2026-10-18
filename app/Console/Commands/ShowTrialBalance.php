<?php

namespace App\Console\Commands;

use App\Services\ReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ShowTrialBalance extends Command
{
    protected $signature = 'ledger:trial-balance {--as-of= : Include entries up to this date (Y-m-d)}';

    protected $description = 'Print the trial balance and check that debits equal credits';

    public function handle(ReportService $reports): int
    {
        $asOf = $this->option('as-of');
        $report = $reports->trialBalance(is_string($asOf) ? Carbon::parse($asOf) : null);

        $this->table(
            ['Code', 'Account', 'Debit', 'Credit'],
            $report->rows->map(fn (array $row) => [
                $row['code'],
                $row['name'],
                $row['debit']->isZero() ? '' : $row['debit']->format(),
                $row['credit']->isZero() ? '' : $row['credit']->format(),
            ])->push(['', 'TOTAL', $report->totalDebit->format(), $report->totalCredit->format()])->all(),
        );

        if (! $report->isBalanced()) {
            $this->error('The ledger is out of balance.');

            return self::FAILURE;
        }

        $this->info('Debits equal credits.');

        return self::SUCCESS;
    }
}
