<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Support\Money;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'type', 'is_active'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Balance on the account's normal side (positive = normal balance).
     */
    public function normalBalance(int $debits, int $credits): Money
    {
        $net = $debits - $credits;

        return Money::of($this->type->isDebitNormal() ? $net : -$net);
    }

    /**
     * Uses the `lines_sum_*` aggregates loaded by the repository.
     */
    public function loadedBalance(): Money
    {
        return $this->normalBalance((int) $this->getAttribute('lines_sum_debit'), (int) $this->getAttribute('lines_sum_credit'));
    }
}
