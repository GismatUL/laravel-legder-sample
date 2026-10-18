<?php

namespace App\Repositories\Eloquent;

use App\Models\BankTransaction;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentBankTransactionRepository implements BankTransactionRepositoryInterface
{
    public function insertNew(array $rows): int
    {
        return DB::transaction(fn () => collect($rows)
            ->chunk(500)
            ->sum(fn ($chunk) => BankTransaction::query()->insertOrIgnore($chunk->values()->all())));
    }

    public function findForUpdate(int $id): BankTransaction
    {
        return BankTransaction::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function chunkUnreconciledIncoming(int $size, Closure $callback): void
    {
        BankTransaction::query()
            ->unreconciled()
            ->where('amount', '>', 0)
            ->chunkById($size, $callback);
    }

    public function countUnreconciled(): int
    {
        return BankTransaction::query()->unreconciled()->count();
    }

    public function paginate(?bool $reconciled, int $perPage): LengthAwarePaginator
    {
        return BankTransaction::query()
            ->with('payment.invoice')
            ->when($reconciled === true, fn ($query) => $query->whereNotNull('reconciled_at'))
            ->when($reconciled === false, fn ($query) => $query->whereNull('reconciled_at'))
            ->orderByDesc('booked_on')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreconciledIncoming(int $limit): Collection
    {
        return BankTransaction::query()
            ->unreconciled()
            ->where('amount', '>', 0)
            ->orderBy('booked_on')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function markReconciled(BankTransaction $transaction, int $paymentId, string $method, ?int $userId = null): void
    {
        $transaction->forceFill([
            'payment_id' => $paymentId,
            'match_method' => $method,
            'matched_by' => $userId,
            'reconciled_at' => now(),
        ])->save();
    }
}
