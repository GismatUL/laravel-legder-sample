<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['1000', 'Bank', AccountType::Asset],
            ['1100', 'Accounts receivable', AccountType::Asset],
            ['2100', 'VAT payable', AccountType::Liability],
            ['3000', 'Owner equity', AccountType::Equity],
            ['4000', 'Sales revenue', AccountType::Revenue],
            ['6000', 'Office expenses', AccountType::Expense],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            Account::query()->updateOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
        }
    }
}
