<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<Money, Money|int>
 */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Money
    {
        return Money::of((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return $value instanceof Money ? $value->minor : $value;
    }
}
