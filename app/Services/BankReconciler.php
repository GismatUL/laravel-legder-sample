<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Matches money received on the bank statement to payments recorded in
 * the system. A match needs the invoice number in the bank reference and
 * the exact amount. Anything else stays unreconciled for a person to review.
 */
class BankReconciler
{
    public function reconcile(BankTransaction $transaction): bool
    {
        if ($transaction->reconciled_at !== null || $transaction->amount->isNegative()) {
            return false;
        }

        $prefix = preg_quote(config('ledger.invoice_prefix'), '/');

        if (! preg_match("/{$prefix}\d+/i", $transaction->reference, $match)) {
            return false;
        }

        return DB::transaction(function () use ($transaction, $match) {
            $transaction = BankTransaction::query()->whereKey($transaction->getKey())->lockForUpdate()->first();

            if ($transaction->reconciled_at !== null) {
                return false;
            }

            // If several payments on the invoice have the same amount, take
            // the one recorded closest to the bank booking date.
            $payment = Payment::query()
                ->whereHas('invoice', fn ($q) => $q->where('number', strtoupper($match[0])))
                ->where('amount', $transaction->amount->minor)
                ->whereDoesntHave('bankTransaction')
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (Payment $p) => abs($p->paid_on->diffInDays($transaction->booked_on)))
                ->first();

            if (! $payment) {
                return false;
            }

            $transaction->forceFill([
                'payment_id' => $payment->getKey(),
                'reconciled_at' => now(),
            ])->save();

            return true;
        });
    }
}
