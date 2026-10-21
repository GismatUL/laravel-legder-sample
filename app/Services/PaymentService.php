<?php

namespace App\Services;

use App\Data\EntryLine;
use App\Data\NewJournalEntry;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BillingException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Outbox\Outbox;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ChartOfAccounts $chart,
        private readonly InvoiceRepositoryInterface $invoices,
        private readonly PaymentRepositoryInterface $payments,
        private readonly Outbox $outbox,
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
        PaymentMethod $method,
        string $idempotencyKey,
        ?string $reference = null,
    ): Payment {
        if (! $amount->greaterThan(Money::zero())) {
            throw BillingException::invalidAmount();
        }

        return DB::transaction(function () use ($invoice, $amount, $paidOn, $method, $idempotencyKey, $reference) {
            // Lock first, then check the key: a duplicate request waits here
            // until the first one commits, and then finds its payment. The
            // unique index on payments.idempotency_key backs this up for a
            // key reused on a different invoice (a different row lock).
            $invoice = $this->invoices->findForUpdate($invoice->getKey());

            if ($existing = $this->payments->findByIdempotencyKey($idempotencyKey)) {
                return $this->assertSamePayment($existing, $invoice, $amount, $paidOn, $method, $reference);
            }

            if (! $invoice->status->acceptsPayments()) {
                throw BillingException::invalidTransition($invoice->status, 'record a payment on');
            }

            if ($invoice->issue_date !== null && $paidOn->lt($invoice->issue_date)) {
                throw BillingException::paymentBeforeIssue($invoice->issue_date);
            }

            // A payment is recorded when the money has arrived, not in advance.
            if ($paidOn->gt(today())) {
                throw BillingException::paymentInFuture();
            }

            if ($amount->greaterThan($invoice->outstanding())) {
                throw BillingException::overpayment($amount, $invoice->outstanding());
            }

            $paid = $invoice->amount_paid->add($amount);
            $status = $paid->equals($invoice->total) ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;

            if (! $invoice->status->canTransitionTo($status)) {
                throw BillingException::invalidTransition($invoice->status, 'record a payment on');
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

            $this->invoices->update($invoice, [
                'amount_paid' => $paid,
                'status' => $status,
            ]);

            // Committed together with the payment, delivered after commit.
            $eventId = "payment_recorded:{$payment->getKey()}";
            $this->outbox->record('payment_recorded', $eventId, ['payment_id' => $payment->getKey(), 'event_id' => $eventId]);

            DB::afterCommit(fn () => Log::info('Payment recorded', [
                'payment_id' => $payment->getKey(),
                'invoice_id' => $invoice->getKey(),
                'amount_minor' => $amount->minor,
                'invoice_status' => $status->value,
            ]));

            return $payment;
        }, attempts: LedgerService::DEADLOCK_ATTEMPTS);
    }

    /**
     * A retry must describe the same payment. Every field that defines it is
     * compared, so a key reused for another date or method is a 409, not a
     * silent replay of an unrelated payment.
     */
    private function assertSamePayment(
        Payment $existing,
        Invoice $invoice,
        Money $amount,
        CarbonInterface $paidOn,
        PaymentMethod $method,
        ?string $reference,
    ): Payment {
        $same = (int) $existing->invoice_id === (int) $invoice->getKey()
            && $existing->amount->equals($amount)
            && $existing->paid_on->toDateString() === $paidOn->toDateString()
            && $existing->method === $method
            && $existing->reference === $reference;

        if (! $same) {
            throw BillingException::idempotencyConflict($existing->idempotency_key);
        }

        return $existing;
    }
}
