<?php

namespace App\Console\Commands;

use App\Services\BankStatementImporter;
use Illuminate\Console\Command;

class ImportBankStatement extends Command
{
    protected $signature = 'bank:import {path : CSV file with date, amount, reference columns}';

    protected $description = 'Import a bank statement and queue automatic reconciliation';

    public function handle(BankStatementImporter $importer): int
    {
        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("Cannot read [{$path}].");

            return self::FAILURE;
        }

        $count = $importer->import($path);

        $this->info($count > 0
            ? "Imported {$count} new transactions. Reconciliation has been queued."
            : 'No new transactions (statement already imported).');

        return self::SUCCESS;
    }
}
