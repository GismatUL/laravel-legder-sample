<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
        ];
    }

    /**
     * @param  list<array{0: int, 1: int, 2?: int}>  $lines  [quantity, unit price in minor units, tax rate in basis points]
     */
    public function withLines(array $lines): static
    {
        return $this->afterCreating(function (Invoice $invoice) use ($lines) {
            foreach ($lines as $i => $line) {
                $invoice->lines()->create([
                    'description' => 'Item '.($i + 1),
                    'quantity' => $line[0],
                    'unit_price' => $line[1],
                    'tax_rate_bp' => $line[2] ?? 0,
                ]);
            }
        });
    }
}
