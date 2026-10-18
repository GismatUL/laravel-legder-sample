<?php

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Model events stay on: the ledger's immutability guards are model events.
    public function run(InvoiceService $invoices, PaymentService $payments): void
    {
        $this->call(ChartOfAccountsSeeder::class);

        // Local demo users, password "password" (UserFactory). One per role.
        foreach (UserRole::cases() as $role) {
            User::factory()->role($role)->create([
                'name' => $role->getLabel(),
                'email' => "{$role->value}@example.com",
            ]);
        }

        $customers = Customer::factory(3)->create();

        // Paid in full
        $paid = $invoices->issue(Invoice::factory()->for($customers[0])->withLines([[10, 5000, 1800], [1, 25000, 1800]])->create());
        $payments->record($paid, $paid->total, today(), PaymentMethod::BankTransfer, 'seed-1', "Transfer {$paid->number}");

        // Partially paid
        $partial = $invoices->issue(Invoice::factory()->for($customers[1])->withLines([[3, 120000, 1800]])->create());
        $payments->record($partial, Money::parse('2000.00'), today(), PaymentMethod::BankTransfer, 'seed-2', "Transfer {$partial->number}");

        // Issued, not paid yet
        $invoices->issue(Invoice::factory()->for($customers[2])->withLines([[2, 45000]])->create());

        // Still a draft
        Invoice::factory()->for($customers[0])->withLines([[1, 99900, 1800]])->create();
    }
}
