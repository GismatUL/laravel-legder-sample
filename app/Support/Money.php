<?php

namespace App\Support;

use App\Exceptions\MoneyException;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money value in minor units (e.g. cents).
 *
 * Floats are never used for amounts: 0.1 + 0.2 !== 0.3 in IEEE 754,
 * and rounding errors in a ledger turn into balances that don't add up.
 *
 * PHP silently turns an integer that overflows into a float, so every
 * operation checks its result and throws MoneyException instead.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function of(int $minor, ?string $currency = null): self
    {
        $currency ??= (string) config('ledger.currency');
        Currency::assertValid($currency);

        return new self($minor, $currency);
    }

    public static function zero(?string $currency = null): self
    {
        return self::of(0, $currency);
    }

    /**
     * Parse a decimal string such as "1234.56" without going through float.
     * The number of decimals allowed is the currency's (2 for AZN, 0 for JPY).
     */
    public static function parse(string $amount, ?string $currency = null): self
    {
        $currency ??= (string) config('ledger.currency');
        $decimals = Currency::minorUnits($currency);
        $amount = trim($amount);
        $fraction = $decimals > 0 ? "(?:\\.(\\d{1,{$decimals}}))?" : '';

        if (! preg_match("/^(-)?(\\d+){$fraction}$/", $amount, $m)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        // (int) of a string longer than PHP_INT_MAX saturates instead of failing.
        $whole = ltrim($m[2], '0');

        if (strlen($whole) > 18) {
            throw MoneyException::overflow();
        }

        $minor = self::checked((int) $whole * 10 ** $decimals);
        $minor = self::checked($minor + (int) str_pad($m[3] ?? '', $decimals, '0'));

        return self::of($m[1] === '-' ? -$minor : $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor + $other->minor), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->minor - $other->minor), $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self(self::checked($this->minor * $factor), $this->currency);
    }

    /**
     * Percentage in basis points (1800 = 18%), rounded half up.
     */
    public function percentage(int $basisPoints): self
    {
        $raw = self::checked($this->minor * $basisPoints);
        $rounded = intdiv(self::checked($raw + ($raw >= 0 ? 5000 : -5000)), 10000);

        return new self($rounded, $this->currency);
    }

    /**
     * Split into parts by ratio without losing a cent: the remainder
     * goes to the first parts, so the parts always sum to the original.
     *
     * @param  list<int>  $ratios
     * @return array<int, self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);

        if ($total <= 0) {
            throw new InvalidArgumentException('Ratios must sum to a positive number.');
        }

        if ($this->minor < 0) {
            throw new InvalidArgumentException('Only non-negative amounts can be allocated.');
        }

        $remainder = $this->minor;
        $parts = [];

        foreach ($ratios as $ratio) {
            $share = intdiv(self::checked($this->minor * $ratio), $total);
            $parts[] = $share;
            $remainder -= $share;
        }

        for ($i = 0; $remainder > 0; $i++, $remainder--) {
            $parts[$i]++;
        }

        return array_map(fn (int $minor) => new self($minor, $this->currency), $parts);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function toDecimal(): string
    {
        return $this->sign().$this->unsignedDecimal(fn (int $whole) => (string) $whole);
    }

    public function format(): string
    {
        return $this->sign().$this->unsignedDecimal(fn (int $whole) => number_format($whole)).' '.$this->currency;
    }

    /**
     * @return array{amount: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->toDecimal(), 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function sign(): string
    {
        return $this->minor < 0 ? '-' : '';
    }

    /**
     * @param  callable(int): string  $formatWhole
     */
    private function unsignedDecimal(callable $formatWhole): string
    {
        $decimals = Currency::minorUnits($this->currency);
        // abs(PHP_INT_MIN) overflows; no ledger amount gets near it, but don't guess.
        $abs = self::checked(abs($this->minor));
        $factor = 10 ** $decimals;
        $whole = $formatWhole(intdiv($abs, $factor));

        return $decimals === 0 ? $whole : $whole.'.'.str_pad((string) ($abs % $factor), $decimals, '0', STR_PAD_LEFT);
    }

    private static function checked(int|float $value): int
    {
        if (! is_int($value)) {
            throw MoneyException::overflow();
        }

        return $value;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
