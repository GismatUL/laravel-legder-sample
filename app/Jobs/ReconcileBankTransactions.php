<?php

namespace App\Jobs;

use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Services\BankReconciler;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs automatic bank reconciliation in the background after a statement
 * import.
 *
 * Unique until processing: while a run is waiting in the queue, more imports
 * don't queue more runs (the waiting one will see their rows). Once a run
 * starts, the lock is released, so an import during the run queues a new
 * one. With plain ShouldBeUnique that dispatch would be dropped, and rows
 * imported after the running chunk passed them would wait for the next import.
 *
 * Safe to retry: each row is matched in its own transaction under row locks,
 * and an already reconciled row is skipped. A retry only redoes unmatched rows.
 */
class ReconcileBankTransactions implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [10, 60];

    public int $timeout = 120;

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

    /**
     * After the last attempt. The job is also stored in failed_jobs for
     * `queue:retry`. A QueryException message contains the SQL bindings
     * (references, amounts), so only its SQLSTATE is logged.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Bank reconciliation failed', [
            'exception' => $e::class,
            'message' => $e instanceof QueryException ? "SQLSTATE {$e->getCode()}" : $e->getMessage(),
        ]);
    }
}
