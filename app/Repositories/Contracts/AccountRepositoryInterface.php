<?php

namespace App\Repositories\Contracts;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface AccountRepositoryInterface
{
    public function findByCode(string $code): Account;

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Account>
     */
    public function findManyKeyedById(array $ids): Collection;

    /**
     * @return Collection<int, Account>
     */
    public function active(): Collection;

    /**
     * Accounts with `lines_sum_debit` / `lines_sum_credit` loaded in the same query.
     *
     * @return LengthAwarePaginator<int, Account>
     */
    public function paginateWithTotals(?AccountType $type, ?bool $active, int $perPage): LengthAwarePaginator;

    public function withTotals(Account $account): Account;

    public function balance(Account $account): Money;

    public function hasPostings(Account $account): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Account;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Account $account, array $attributes): Account;
}
