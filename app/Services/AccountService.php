<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Exceptions\LedgerException;
use App\Models\Account;
use App\Reports\ReportCache;
use App\Repositories\Contracts\AccountRepositoryInterface;

class AccountService
{
    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
        private readonly ReportCache $reports,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  code, name, type, is_active
     */
    public function create(array $attributes): Account
    {
        return $this->accounts->create($attributes);
    }

    /**
     * Once an account has postings, changing its code or type would
     * silently change the meaning of past entries.
     *
     * @param  array{code?: string, name?: string, type?: AccountType|string, is_active?: bool}  $attributes
     */
    public function update(Account $account, array $attributes): Account
    {
        $type = $attributes['type'] ?? null;
        $type = is_string($type) ? AccountType::from($type) : $type;
        $changesCode = isset($attributes['code']) && $attributes['code'] !== $account->code;
        $changesType = $type !== null && $type !== $account->type;

        if (($changesCode || $changesType) && $this->accounts->hasPostings($account)) {
            throw LedgerException::accountHasPostings($account->code);
        }

        $account = $this->accounts->update($account, $attributes);

        // Cached reports show account codes and names.
        $this->reports->invalidate();

        return $account;
    }
}
