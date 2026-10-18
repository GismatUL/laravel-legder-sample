<?php

namespace App\Jobs;

use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Services\BankReconciler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs automatic bank reconciliation in the background after a statement
 * import. ShouldBeUnique keeps a second import from starting a parallel run;
 * the per-row locks in BankReconciler make overlapping runs safe anyway.
 */
class ReconcileBankTransactions implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function handle(BankReconciler $reconciler, BankTransactionRepositoryInterface $transactions): void
    {
        $matched = 0;

        $transactions->chunkUnreconciledIncoming(200, function ($chunk) use ($reconciler, &$matched) {
            foreach ($chunk as $transaction) {
                $matched += (int) $reconciler->reconcile($transaction);
            }
        });

        Log::info('Bank reconciliation finished', [
            'matched' => $matched,
            'unreconciled' => $transactions->countUnreconciled(),
        ]);
    }
}
