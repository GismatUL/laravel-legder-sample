<?php

namespace App\Services;

use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;

/**
 * The accounts the billing workflow posts to, as configured in config/ledger.php.
 */
class ChartOfAccounts
{
    public function __construct(private readonly AccountRepositoryInterface $accounts) {}

    public function bank(): Account
    {
        return $this->get('bank');
    }

    public function receivable(): Account
    {
        return $this->get('receivable');
    }

    public function vatPayable(): Account
    {
        return $this->get('vat_payable');
    }

    public function sales(): Account
    {
        return $this->get('sales');
    }

    private function get(string $key): Account
    {
        return $this->accounts->findByCode((string) config("ledger.accounts.{$key}"));
    }
}
