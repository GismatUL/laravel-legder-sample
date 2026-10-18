<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * ISO 4217 currency codes and their number of minor units (decimals).
 *
 * Most currencies have 2 decimals; the exceptions below don't, and treating
 * them as 2-decimal would make every amount off by a factor of 10 or 100.
 */
final class Currency
{
    private const MINOR_UNITS = [
        // 0 decimals
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // 3 decimals
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function minorUnits(string $code): int
    {
        self::assertValid($code);

        return self::MINOR_UNITS[$code] ?? 2;
    }

    /**
     * Shape check only (three upper-case letters). The ledger is single-currency,
     * so the configured code is the one that matters; see config/ledger.php.
     */
    public static function assertValid(string $code): void
    {
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code [{$code}].");
        }
    }
}
