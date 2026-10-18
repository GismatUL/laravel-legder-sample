<?php

namespace App\Services;

use App\Jobs\ReconcileBankTransactions;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SplFileObject;

/**
 * Imports a bank statement CSV with the columns: date, amount, reference.
 *
 * Each row gets a fingerprint, so importing the same statement twice (or a
 * statement that overlaps a previous one) doesn't create duplicate rows.
 */
class BankStatementImporter
{
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

        while (! $file->eof()) {
            $values = $file->fgetcsv();

            if ($values === false || $values === [null]) {
                continue;
            }

            $row = array_combine($header, array_map('trim', $values));
            $amount = Money::parse($row['amount']);

            // Two identical rows in one statement are two real transactions,
            // so the occurrence number is part of the fingerprint.
            $base = "{$row['date']}|{$amount->minor}|{$row['reference']}";
            $occurrence = $seen[$base] = ($seen[$base] ?? 0) + 1;

            $rows[] = [
                'booked_on' => $row['date'],
                'amount' => $amount->minor,
                'reference' => $row['reference'],
                'fingerprint' => hash('sha256', "{$base}|{$occurrence}"),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $imported = DB::transaction(fn () => collect($rows)
            ->chunk(500)
            ->sum(fn ($chunk) => DB::table('bank_transactions')->insertOrIgnore($chunk->values()->all())));

        if ($imported > 0) {
            ReconcileBankTransactions::dispatch();
        }

        return $imported;
    }
}
