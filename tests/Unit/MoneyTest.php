<?php

namespace Tests\Unit;

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
    }
}
