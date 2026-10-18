<?php

namespace Tests\Feature;

use App\Models\Account;
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

    protected function account(string $key): Account
    {
        return Account::byCode(config("ledger.accounts.{$key}"));
    }
}
