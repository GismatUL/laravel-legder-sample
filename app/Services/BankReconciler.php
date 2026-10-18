<?php

namespace App\Services;

use App\Exceptions\BankingException;
use App\Models\ActivityLog;
use App\Models\BankTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Matches money received on the bank statement to payments recorded in
 * the system. A match needs the invoice number in the bank reference and
 * the exact amount. Anything else stays unreconciled for a person to match
 * on the "Match bank lines" page (matchManually()).
 */
class BankReconciler
{
    public function __construct(
        private readonly BankTransactionRepositoryInterface $transactions,
        private readonly PaymentRepositoryInterface $payments,
    ) {}

    public function reconcile(BankTransaction $transaction): bool
    {
        if ($transaction->reconciled_at !== null || $transaction->amount->isNegative()) {
            return false;
        }

        $prefix = preg_quote((string) config('ledger.invoice_prefix'), '/');

        if (! preg_match("/{$prefix}\d+/i", $transaction->reference, $match)) {
            return false;
        }

        try {
            return $this->match($transaction, strtoupper($match[0]));
        } catch (UniqueConstraintViolationException) {
            // bank_transactions.payment_id is unique: a concurrent run matched
            // this payment to another statement line first. Leave this one open.
            return false;
        }
    }

    private function match(BankTransaction $transaction, string $invoiceNumber): bool
    {
        return DB::transaction(function () use ($transaction, $invoiceNumber) {
            $transaction = $this->transactions->findForUpdate($transaction->getKey());

            if ($transaction->reconciled_at !== null) {
                return false;
            }

            // If several payments on the invoice have the same amount, take
            // the one recorded closest to the bank booking date.
            $payment = $this->payments->unmatchedForUpdate($invoiceNumber, $transaction->amount)
                ->sortBy(fn (Payment $p) => abs($p->paid_on->diffInDays($transaction->booked_on)))
                ->first();

            if (! $payment) {
                return false;
            }

            $this->transactions->markReconciled($transaction, $payment->getKey(), 'auto');

            return true;
        });
    }

    /**
     * A person matches a bank line the automatic run couldn't (no invoice
     * number in the reference). Same rules as the automatic match: incoming
     * money, exact amount, one payment per bank line. Locks are taken in the
     * same order as reconcile() (bank line, then payment), so the two can't deadlock.
     */
    public function matchManually(BankTransaction $transaction, Payment $payment): BankTransaction
    {
        try {
            return DB::transaction(function () use ($transaction, $payment) {
                $transaction = $this->transactions->findForUpdate($transaction->getKey());
                $payment = $this->payments->findForUpdate($payment->getKey());

                if ($transaction->reconciled_at !== null) {
                    throw BankingException::alreadyReconciled();
                }

                if (! $transaction->amount->greaterThan(Money::zero())) {
                    throw BankingException::notIncoming();
                }

                if ($payment->bankTransaction()->exists()) {
                    throw BankingException::paymentAlreadyMatched();
                }

                if (! $transaction->amount->equals($payment->amount)) {
                    throw BankingException::amountMismatch($transaction->amount, $payment->amount);
                }

                $user = Auth::user();
                $this->transactions->markReconciled($transaction, $payment->getKey(), 'manual', $user instanceof User ? $user->id : null);

                ActivityLog::record('matched', $transaction, [
                    'payment_id' => $payment->getKey(),
                    'invoice_number' => $payment->invoice->number,
                    'amount' => $payment->amount->toDecimal(),
                ]);

                return $transaction;
            }, attempts: LedgerService::DEADLOCK_ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // The automatic run matched this payment between our check and our write.
            throw BankingException::paymentAlreadyMatched();
        }
    }
}
