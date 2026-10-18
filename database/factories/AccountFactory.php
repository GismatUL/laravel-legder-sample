<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(5000, 9999),
            'name' => fake()->words(2, true),
            'type' => AccountType::Expense,
            'is_active' => true,
        ];
    }
}
