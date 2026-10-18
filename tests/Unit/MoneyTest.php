<?php

namespace Tests\Unit;

use App\Exceptions\MoneyException;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_decimal_strings_without_float_errors(): void
    {
        $this->assertSame(123456, Money::parse('1234.56', 'AZN')->minor);
        $this->assertSame(10, Money::parse('0.1', 'AZN')->minor);
        $this->assertSame(-250, Money::parse('-2.50', 'AZN')->minor);

        // The classic float bug: 0.1 + 0.2 != 0.3
        $sum = Money::parse('0.1', 'AZN')->add(Money::parse('0.2', 'AZN'));
        $this->assertTrue($sum->equals(Money::parse('0.3', 'AZN')));
    }

    public function test_rejects_invalid_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse('12.345', 'AZN');
    }

    /** @return array<string, array{int, int, int}> */
    public static function percentages(): array
    {
        return [
            '18% of 100.00' => [10000, 1800, 1800],
            'rounds half up' => [25, 1800, 5],   // 4.5 -> 5
            'rounds down' => [24, 1800, 4],      // 4.32 -> 4
            'zero rate' => [9999, 0, 0],
        ];
    }

    #[DataProvider('percentages')]
    public function test_percentage_rounds_half_up(int $minor, int $basisPoints, int $expected): void
    {
        $this->assertSame($expected, Money::of($minor, 'AZN')->percentage($basisPoints)->minor);
    }

    public function test_allocation_never_loses_a_cent(): void
    {
        $parts = Money::of(10000, 'AZN')->allocate([1, 1, 1]);

        $this->assertSame([3334, 3333, 3333], array_map(fn (Money $m) => $m->minor, $parts));
        $this->assertSame(10000, array_sum(array_map(fn (Money $m) => $m->minor, $parts)));
    }

    public function test_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, 'AZN')->add(Money::of(100, 'USD'));
    }

    public function test_formats_amounts(): void
    {
        $this->assertSame('1,234.05 AZN', Money::of(123405, 'AZN')->format());
        $this->assertSame('-0.50', Money::of(-50, 'AZN')->toDecimal());
        $this->assertSame('0.00', Money::of(0, 'AZN')->toDecimal());
    }

    /** @return array<string, array{string, string, int, string}> */
    public static function currencyPrecisions(): array
    {
        return [
            '2 decimals (AZN)' => ['AZN', '12.34', 1234, '12.34'],
            '0 decimals (JPY)' => ['JPY', '1250', 1250, '1250'],
            '3 decimals (KWD)' => ['KWD', '1.234', 1234, '1.234'],
            '3 decimals, short fraction' => ['KWD', '1.5', 1500, '1.500'],
        ];
    }

    #[DataProvider('currencyPrecisions')]
    public function test_uses_the_currency_number_of_decimals(string $currency, string $input, int $minor, string $decimal): void
    {
        $money = Money::parse($input, $currency);

        $this->assertSame($minor, $money->minor);
        $this->assertSame($decimal, $money->toDecimal());
    }

    /** @return array<string, array{string, string}> */
    public static function invalidAmounts(): array
    {
        return [
            'too many decimals for AZN' => ['12.345', 'AZN'],
            'decimals for a 0-decimal currency' => ['12.5', 'JPY'],
            'four decimals for KWD' => ['1.2345', 'KWD'],
            'float notation' => ['1e3', 'AZN'],
            'thousands separator' => ['1,000.00', 'AZN'],
            'empty' => ['', 'AZN'],
            'plus sign' => ['+1.00', 'AZN'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_amounts_that_do_not_fit_the_currency(string $amount, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse($amount, $currency);
    }

    public function test_rejects_invalid_currency_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, 'azn');
    }

    public function test_parse_detects_overflow_instead_of_saturating(): void
    {
        // (int) "99999999999999999999" would silently become PHP_INT_MAX.
        $this->expectException(MoneyException::class);

        Money::parse('99999999999999999999', 'AZN');
    }

    public function test_parse_accepts_the_largest_representable_amount(): void
    {
        $max = Money::parse('92233720368547758.07', 'AZN');

        $this->assertSame(PHP_INT_MAX, $max->minor);
        $this->assertSame('92233720368547758.07', $max->toDecimal());
    }

    /** @return array<string, array{callable(): Money}> */
    public static function overflows(): array
    {
        return [
            'add' => [fn () => Money::of(PHP_INT_MAX, 'AZN')->add(Money::of(1, 'AZN'))],
            'subtract' => [fn () => Money::of(PHP_INT_MIN, 'AZN')->subtract(Money::of(1, 'AZN'))],
            'multiply' => [fn () => Money::of(999999999999999, 'AZN')->multiply(1000000)],
            'percentage' => [fn () => Money::of(PHP_INT_MAX, 'AZN')->percentage(1800)],
            'parse just above max' => [fn () => Money::parse('92233720368547758.08', 'AZN')],
        ];
    }

    /**
     * PHP turns an overflowing int into a float. These used to produce a
     * TypeError (or a wrong amount); now they fail with a domain error.
     *
     * @param  callable(): Money  $operation
     */
    #[DataProvider('overflows')]
    public function test_arithmetic_overflow_is_a_domain_error(callable $operation): void
    {
        $this->expectException(MoneyException::class);

        $operation();
    }

    public function test_negative_and_zero_helpers(): void
    {
        $this->assertTrue(Money::parse('-0.01', 'AZN')->isNegative());
        $this->assertTrue(Money::parse('0', 'AZN')->isZero());
        $this->assertTrue(Money::parse('-0.00', 'AZN')->isZero());
        $this->assertFalse(Money::of(100, 'AZN')->equals(Money::of(100, 'USD')));
    }
}
