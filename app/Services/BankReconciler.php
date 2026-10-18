<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Payment;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Matches money received on the bank statement to payments recorded in
 * the system. A match needs the invoice number in the bank reference and
 * the exact amount. Anything else stays unreconciled for a person to review.
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

        return DB::transaction(function () use ($transaction, $match) {
            $transaction = $this->transactions->findForUpdate($transaction->getKey());

            if ($transaction->reconciled_at !== null) {
                return false;
            }

            // If several payments on the invoice have the same amount, take
            // the one recorded closest to the bank booking date.
            $payment = $this->payments->unmatchedForUpdate(strtoupper($match[0]), $transaction->amount)
                ->sortBy(fn (Payment $p) => abs($p->paid_on->diffInDays($transaction->booked_on)))
                ->first();

            if (! $payment) {
                return false;
            }

            $this->transactions->markReconciled($transaction, $payment->getKey());

            return true;
        });
    }
}
