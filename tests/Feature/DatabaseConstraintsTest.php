<?php

namespace Tests\Feature;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\LedgerService;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The database rejects writes that bypass the services (raw SQL, a bug in
 * new code). These tests write with the query builder on purpose.
 */
class DatabaseConstraintsTest extends LedgerTestCase
{
    private function requireMySql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('CHECK constraints are added on MySQL only (see the journal_lines, invoices and payments migrations).');
        }
    }

    private function postedEntryId(): int
    {
        return app(LedgerService::class)->post(new NewJournalEntry(today(), 'Sale', [
            EntryLine::debit($this->account('bank'), Money::parse('10.00')),
            EntryLine::credit($this->account('sales'), Money::parse('10.00')),
        ]))->id;
    }

    public function test_a_journal_entry_with_lines_cannot_be_deleted_at_database_level(): void
    {
        $id = $this->postedEntryId();

        $this->expectException(QueryException::class);

        DB::table('journal_entries')->where('id', $id)->delete();
    }

    public function test_a_journal_line_must_be_one_sided(): void
    {
        $this->requireMySql();
        $id = $this->postedEntryId();

        foreach ([[100, 100], [0, 0]] as [$debit, $credit]) {
            $this->assertThrows(fn () => DB::table('journal_lines')->insert([
                'journal_entry_id' => $id,
                'account_id' => $this->account('bank')->id,
                'debit' => $debit,
                'credit' => $credit,
            ]), QueryException::class);
        }
    }

    public function test_an_invoice_cannot_be_overpaid_or_have_inconsistent_totals(): void
    {
        $this->requireMySql();
        $invoice = app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000, 1800]])->create());

        $this->assertThrows(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['amount_paid' => 11801]), QueryException::class);
        $this->assertThrows(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['total' => 1]), QueryException::class);
    }

    public function test_a_payment_amount_must_be_positive(): void
    {
        $this->requireMySql();
        $invoice = app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000]])->create());

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'invoice_id' => $invoice->id,
            'amount' => 0,
            'paid_on' => today()->toDateString(),
            'method' => 'cash',
            'idempotency_key' => 'raw-1',
            'journal_entry_id' => $invoice->journal_entry_id,
        ]);
    }
}
