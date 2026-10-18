<?php

namespace App\Rules;

use App\Support\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A positive or zero decimal string in the ledger currency, e.g. "118.00".
 *
 * Strings, not JSON numbers: a client that sends 0.1 + 0.2 as a float has
 * already lost precision before the request arrives. The integer part is
 * capped at 13 digits, so amounts multiplied by a quantity or summed over
 * many lines stay far below PHP_INT_MAX.
 */
class MoneyAmount implements ValidationRule
{
    public const MAX_WHOLE_DIGITS = 13;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $decimals = Currency::minorUnits((string) config('ledger.currency'));
        $fraction = $decimals > 0 ? "(\\.\\d{1,{$decimals}})?" : '';

        if (! is_string($value) || ! preg_match('/^\d{1,'.self::MAX_WHOLE_DIGITS."}{$fraction}$/", $value)) {
            $fail($decimals > 0
                ? "The :attribute must be a decimal string with up to {$decimals} decimals, e.g. \"12.50\"."
                : 'The :attribute must be a whole number string, e.g. "1250".');
        }
    }
}
