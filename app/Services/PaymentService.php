<?php

namespace App\Services;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Enums\InvoiceStatus;
use App\Exceptions\BillingException;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly LedgerService $ledger) {}

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
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($existing = Payment::query()->where('idempotency_key', $idempotencyKey)->first()) {
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

            $accounts = config('ledger.accounts');

            $entry = $this->ledger->post(new NewJournalEntry(
                date: $paidOn,
                description: "Payment for invoice {$invoice->number}",
                lines: [
                    EntryLine::debit(Account::byCode($accounts['bank']), $amount, $reference),
                    EntryLine::credit(Account::byCode($accounts['receivable']), $amount, $invoice->number),
                ],
                idempotencyKey: "payment:{$idempotencyKey}",
                source: $invoice,
            ));

            $payment = $invoice->payments()->create([
                'amount' => $amount,
                'paid_on' => $paidOn,
                'method' => $method,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'journal_entry_id' => $entry->getKey(),
            ]);

            $paid = $invoice->amount_paid->add($amount);

            $invoice->forceFill([
                'amount_paid' => $paid,
                'status' => $paid->equals($invoice->total) ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid,
            ])->save();

            return $payment;
        });
    }
}
