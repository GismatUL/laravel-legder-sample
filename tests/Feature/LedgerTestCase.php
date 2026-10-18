<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Support\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class LedgerTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
    }

    /**
     * @param  string  $key  Key in config('ledger.accounts'), e.g. "bank"
     */
    protected function account(string $key): Account
    {
        return $this->accountByCode((string) config("ledger.accounts.{$key}"));
    }

    protected function accountByCode(string $code): Account
    {
        return app(AccountRepositoryInterface::class)->findByCode($code);
    }

    protected function balance(string $key): Money
    {
        return app(AccountRepositoryInterface::class)->balance($this->account($key));
    }
}
