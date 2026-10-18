<?php

namespace App\Repositories\Contracts;

use App\Models\BankTransaction;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface BankTransactionRepositoryInterface
{
    /**
     * Insert statement rows, skipping rows whose fingerprint already exists.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return int Number of rows actually inserted
     */
    public function insertNew(array $rows): int;

    public function findForUpdate(int $id): BankTransaction;

    /**
     * @param  Closure(Collection<int, BankTransaction>): void  $callback
     */
    public function chunkUnreconciledIncoming(int $size, Closure $callback): void;

    public function countUnreconciled(): int;

    /**
     * @return LengthAwarePaginator<int, BankTransaction>
     */
    public function paginate(?bool $reconciled, int $perPage): LengthAwarePaginator;

    /**
     * Unreconciled incoming lines, oldest first.
     *
     * @return Collection<int, BankTransaction>
     */
    public function unreconciledIncoming(int $limit): Collection;

    /**
     * @param  'auto'|'manual'  $method
     */
    public function markReconciled(BankTransaction $transaction, int $paymentId, string $method, ?int $userId = null): void;
}
