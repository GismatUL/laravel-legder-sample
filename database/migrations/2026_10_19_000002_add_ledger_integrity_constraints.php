<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database-level backstops for rules the services already enforce, so a bug
 * or a hand-written SQL statement can't break them either.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private array $checks = [
        'journal_lines' => [
            'chk_journal_lines_one_side' => '(debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)',
        ],
        'invoices' => [
            'chk_invoices_total' => 'total = subtotal + tax_total',
            'chk_invoices_not_overpaid' => 'amount_paid <= total',
        ],
        'payments' => [
            'chk_payments_positive' => 'amount > 0',
        ],
    ];

    public function up(): void
    {
        // Deleting a journal entry must never silently take its lines with it.
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
        });

        // CHECK constraints are enforced by MySQL 8.0.16+. SQLite can't add
        // them to an existing table, so the SQLite test database relies on
        // the service-level checks alone.
        if (! $this->supportsChecks()) {
            return;
        }

        foreach ($this->checks as $table => $checks) {
            foreach ($checks as $name => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }

    public function down(): void
    {
        if ($this->supportsChecks()) {
            foreach ($this->checks as $table => $checks) {
                foreach (array_keys($checks) as $name) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
                }
            }
        }

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
        });
    }

    private function supportsChecks(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
