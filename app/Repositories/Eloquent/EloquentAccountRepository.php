<?php

namespace App\Repositories\Eloquent;

use App\Enums\AccountType;
use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class EloquentAccountRepository implements AccountRepositoryInterface
{
    public function findByCode(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    public function findManyKeyedById(array $ids): Collection
    {
        return Account::query()->findMany($ids)->keyBy('id');
    }

    public function active(): Collection
    {
        return Account::query()->where('is_active', true)->orderBy('code')->get();
    }

    public function paginateWithTotals(?AccountType $type, ?bool $active, int $perPage): LengthAwarePaginator
    {
        return Account::query()
            ->withSum('lines', 'debit')
            ->withSum('lines', 'credit')
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($active !== null, fn ($query) => $query->where('is_active', $active))
            ->orderBy('code')
            ->paginate($perPage);
    }

    public function withTotals(Account $account): Account
    {
        return $account->loadSum('lines', 'debit')->loadSum('lines', 'credit');
    }

    public function balance(Account $account): Money
    {
        return $this->withTotals($account)->loadedBalance();
    }

    public function hasPostings(Account $account): bool
    {
        return $account->lines()->exists();
    }

    public function create(array $attributes): Account
    {
        return Account::query()->create($attributes);
    }

    public function update(Account $account, array $attributes): Account
    {
        $account->update($attributes);

        return $account;
    }
}
