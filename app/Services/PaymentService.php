<?php

namespace App\Services;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Enums\InvoiceStatus;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly PaymentRepositoryInterface $payments,
    ) {}

    /**
     * Record a customer payment against an invoice and post:
     *   Dr Bank                  amount
     *   Cr Accounts receivable   amount
     *
     * The idempotency key (e.g. the payment provider's transaction id) makes
     * retries safe: a webhook delivered twice records the payment once.
     */
    public function record(
        Invoice $invoice,
        Money $amount,
        CarbonInterface $paidOn,
        string $method,
        string $idempotencyKey,
        ?string $reference = null,
    ): Payment {
        if (! $amount->greaterThan(Money::zero())) {
            throw BillingException::invalidAmount();
        }

        return DB::transaction(function () use ($invoice, $amount, $paidOn, $method, $idempotencyKey, $reference) {
            // Lock first, then check the key: a duplicate request waits here
            // until the first one commits, and then finds its payment.
            $invoice = $this->invoices->findForUpdate($invoice->getKey());

            if ($existing = $this->payments->findByIdempotencyKey($idempotencyKey)) {
                if ((int) $existing->invoice_id !== (int) $invoice->getKey() || ! $existing->amount->equals($amount)) {
                    throw BillingException::idempotencyConflict($idempotencyKey);
                }

                return $existing;
            }

            if (! $invoice->status->acceptsPayments()) {
                throw BillingException::invalidTransition($invoice->status, 'record a payment on');
            }

            if ($amount->greaterThan($invoice->outstanding())) {
                throw BillingException::overpayment($amount, $invoice->outstanding());
            }

            $entry = $this->ledger->post(new NewJournalEntry(
                date: $paidOn,
                description: "Payment for invoice {$invoice->number}",
                lines: [
                    EntryLine::debit($this->chart->bank(), $amount, $reference),
                    EntryLine::credit($this->chart->receivable(), $amount, $invoice->number),
                ],
                idempotencyKey: "payment:{$idempotencyKey}",
                source: $invoice,
            ));

            $payment = $this->payments->create($invoice, [
                'amount' => $amount,
                'paid_on' => $paidOn,
                'method' => $method,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'journal_entry_id' => $entry->getKey(),
            ]);

            $paid = $invoice->amount_paid->add($amount);

            $this->invoices->update($invoice, [
                'amount_paid' => $paid,
                'status' => $paid->equals($invoice->total) ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid,
            ]);

            return $payment;
        });
    }
}
