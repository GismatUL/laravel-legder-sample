<?php

namespace App\Services;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Enums\InvoiceStatus;
use App\Exceptions\BillingException;
use App\Models\Account;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SequenceService $sequences,
    ) {}

    /**
     * Recompute line and invoice totals from quantities, prices and tax rates.
     * Tax is rounded per line, which is how the amounts are printed on the invoice.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        if (! $invoice->isEditable()) {
            throw BillingException::invalidTransition($invoice->status, 'recalculate');
        }

        $subtotal = Money::zero();
        $tax = Money::zero();

        foreach ($invoice->lines()->get() as $line) {
            $lineSubtotal = Money::of($line->unit_price)->multiply($line->quantity);
            $lineTax = $lineSubtotal->percentage($line->tax_rate_bp);

            $line->forceFill([
                'line_subtotal' => $lineSubtotal->minor,
                'line_tax' => $lineTax->minor,
            ])->save();

            $subtotal = $subtotal->add($lineSubtotal);
            $tax = $tax->add($lineTax);
        }

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total' => $subtotal->add($tax),
        ])->save();

        return $invoice;
    }

    /**
     * Draft -> Issued. Assigns the next invoice number and posts:
     *   Dr Accounts receivable   total
     *   Cr Sales revenue         subtotal
     *   Cr VAT payable           tax
     */
    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = $this->lock($invoice);

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw BillingException::invalidTransition($invoice->status, 'issue');
            }

            $this->recalculate($invoice);

            if ($invoice->total->isZero()) {
                throw BillingException::emptyInvoice();
            }

            $number = config('ledger.invoice_prefix').str_pad((string) $this->sequences->next('invoice'), 6, '0', STR_PAD_LEFT);
            $accounts = config('ledger.accounts');

            $lines = [
                EntryLine::debit(Account::byCode($accounts['receivable']), $invoice->total, $number),
                EntryLine::credit(Account::byCode($accounts['sales']), $invoice->subtotal, $number),
            ];

            if (! $invoice->tax_total->isZero()) {
                $lines[] = EntryLine::credit(Account::byCode($accounts['vat_payable']), $invoice->tax_total, $number);
            }

            $entry = $this->ledger->post(new NewJournalEntry(
                date: today(),
                description: "Invoice {$number} issued to {$invoice->customer->name}",
                lines: $lines,
                idempotencyKey: "invoice:{$invoice->getKey()}:issue",
                source: $invoice,
            ));

            $invoice->forceFill([
                'number' => $number,
                'status' => InvoiceStatus::Issued,
                'issue_date' => today(),
                'due_date' => $invoice->due_date ?? today()->addDays(config('ledger.payment_terms_days')),
                'journal_entry_id' => $entry->getKey(),
            ])->save();

            return $invoice;
        });
    }

    /**
     * Issued -> Void. The original posting stays in the ledger and a
     * reversing entry cancels it out, so the audit trail is complete.
     */
    public function void(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = $this->lock($invoice);

            if (! $invoice->amount_paid->isZero()) {
                throw BillingException::hasPayments();
            }

            if ($invoice->status !== InvoiceStatus::Issued) {
                throw BillingException::invalidTransition($invoice->status, 'void');
            }

            $reversal = $this->ledger->reverse($invoice->journalEntry, today(), "Void invoice {$invoice->number}");

            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'void_entry_id' => $reversal->getKey(),
            ])->save();

            return $invoice;
        });
    }

    /**
     * Re-read the invoice with a row lock, so concurrent issue/void/payment
     * requests on the same invoice run one after another.
     */
    private function lock(Invoice $invoice): Invoice
    {
        return Invoice::query()->with('customer')->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
    }
}
