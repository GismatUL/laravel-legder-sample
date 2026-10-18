<?php

namespace App\Services;

use App\Jobs\ReconcileBankTransactions;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Support\Money;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use SplFileObject;
use Throwable;

/**
 * Imports a bank statement CSV with the columns: date, amount, reference.
 *
 * Each row gets a fingerprint, so importing the same statement twice (or a
 * statement that overlaps a previous one) doesn't create duplicate rows.
 */
class BankStatementImporter
{
    public function __construct(private readonly BankTransactionRepositoryInterface $transactions) {}

    /**
     * @return int Number of new transactions imported
     */
    public function import(string $path): int
    {
        $file = new SplFileObject($path);
        $file->setCsvControl(',', '"', '');

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), (array) $file->fgetcsv());

        if (array_diff(['date', 'amount', 'reference'], $header) !== []) {
            throw new InvalidArgumentException('Statement must have the columns: date, amount, reference.');
        }

        $rows = [];
        $seen = [];
        $line = 1;

        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $line++;

            if ($values === false || $values === [null]) {
                continue;
            }

            if (count($values) !== count($header)) {
                throw new InvalidArgumentException("Line {$line}: expected ".count($header).' columns.');
            }

            $row = array_combine($header, array_map(fn ($value) => trim((string) $value), $values));

            try {
                $date = Carbon::createFromFormat('!Y-m-d', $row['date']);
                $amount = Money::parse($row['amount']);
            } catch (Throwable) {
                throw new InvalidArgumentException("Line {$line}: invalid date or amount.");
            }

            // createFromFormat() rolls invalid dates over (2026-13-01 becomes 2027-01-01).
            if ($date->toDateString() !== $row['date']) {
                throw new InvalidArgumentException("Line {$line}: invalid date or amount.");
            }

            // The column is VARCHAR(255): MySQL in strict mode would reject the whole batch.
            if (mb_strlen($row['reference']) > 255) {
                throw new InvalidArgumentException("Line {$line}: reference is longer than 255 characters.");
            }

            // Two identical rows in one statement are two real transactions,
            // so the occurrence number is part of the fingerprint.
            $base = "{$date->toDateString()}|{$amount->minor}|{$row['reference']}";
            $occurrence = $seen[$base] = ($seen[$base] ?? 0) + 1;

            $rows[] = [
                'booked_on' => $date->toDateString(),
                'amount' => $amount->minor,
                'reference' => $row['reference'],
                'fingerprint' => hash('sha256', "{$base}|{$occurrence}"),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $imported = $this->transactions->insertNew($rows);

        if ($imported > 0) {
            ReconcileBankTransactions::dispatch();
        }

        return $imported;
    }
}
