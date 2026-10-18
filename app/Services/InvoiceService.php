<?php

namespace App\Services;

use App\Data\EntryLine;
use App\Data\InvoiceData;
use App\Data\NewJournalEntry;
use App\Enums\InvoiceStatus;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly SequenceService $sequences,
        private readonly ChartOfAccounts $chart,
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    public function createDraft(InvoiceData $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $invoice = $this->invoices->create([
                'customer_id' => $this->customers->find($data->customerId)->getKey(),
                'status' => InvoiceStatus::Draft,
                'due_date' => $data->dueDate,
                'notes' => $data->notes,
            ]);

            $this->invoices->replaceLines($invoice, $data->lines);

            return $this->recalculate($invoice);
        });
    }

    public function updateDraft(Invoice $invoice, InvoiceData $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice = $this->invoices->findForUpdate($invoice->getKey());

            if (! $invoice->isEditable()) {
                throw BillingException::invalidTransition($invoice->status, 'edit');
            }

            $this->invoices->update($invoice, [
                'customer_id' => $this->customers->find($data->customerId)->getKey(),
                'due_date' => $data->dueDate,
                'notes' => $data->notes,
            ]);

            $this->invoices->replaceLines($invoice, $data->lines);

            return $this->recalculate($invoice);
        });
    }

    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $invoice = $this->invoices->findForUpdate($invoice->getKey());

            if (! $invoice->isEditable()) {
                throw BillingException::invalidTransition($invoice->status, 'delete');
            }

            $this->invoices->delete($invoice);
        });
    }

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

        return $this->invoices->update($invoice, [
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total' => $subtotal->add($tax),
        ]);
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
            // Row lock: concurrent issue/void/payment requests on the same invoice run one after another.
            $invoice = $this->invoices->findForUpdate($invoice->getKey());

            if ($invoice->status !== InvoiceStatus::Draft) {
                throw BillingException::invalidTransition($invoice->status, 'issue');
            }

            $this->recalculate($invoice);

            if ($invoice->total->isZero()) {
                throw BillingException::emptyInvoice();
            }

            $number = config('ledger.invoice_prefix').str_pad((string) $this->sequences->next('invoice'), 6, '0', STR_PAD_LEFT);

            $lines = [
                EntryLine::debit($this->chart->receivable(), $invoice->total, $number),
                EntryLine::credit($this->chart->sales(), $invoice->subtotal, $number),
            ];

            if (! $invoice->tax_total->isZero()) {
                $lines[] = EntryLine::credit($this->chart->vatPayable(), $invoice->tax_total, $number);
            }

            $entry = $this->ledger->post(new NewJournalEntry(
                date: today(),
                description: "Invoice {$number} issued to {$invoice->customer->name}",
                lines: $lines,
                idempotencyKey: "invoice:{$invoice->getKey()}:issue",
                source: $invoice,
            ));

            return $this->invoices->update($invoice, [
                'number' => $number,
                'status' => InvoiceStatus::Issued,
                'issue_date' => today(),
                'due_date' => $invoice->due_date ?? today()->addDays((int) config('ledger.payment_terms_days')),
                'journal_entry_id' => $entry->getKey(),
            ]);
        });
    }

    /**
     * Issued -> Void. The original posting stays in the ledger and a
     * reversing entry cancels it out, so the audit trail is complete.
     */
    public function void(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = $this->invoices->findForUpdate($invoice->getKey());

            if (! $invoice->amount_paid->isZero()) {
                throw BillingException::hasPayments();
            }

            if ($invoice->status !== InvoiceStatus::Issued || $invoice->journalEntry === null) {
                throw BillingException::invalidTransition($invoice->status, 'void');
            }

            $reversal = $this->ledger->reverse($invoice->journalEntry, today(), "Void invoice {$invoice->number}");

            return $this->invoices->update($invoice, [
                'status' => InvoiceStatus::Void,
                'void_entry_id' => $reversal->getKey(),
            ]);
        });
    }
}
