<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable money value in minor units (e.g. cents).
 *
 * Floats are never used for amounts: 0.1 + 0.2 !== 0.3 in IEEE 754,
 * and rounding errors in a ledger turn into balances that don't add up.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    public static function of(int $minor, ?string $currency = null): self
    {
        return new self($minor, $currency ?? config('ledger.currency'));
    }

    public static function zero(?string $currency = null): self
    {
        return self::of(0, $currency);
    }

    /**
     * Parse a decimal string such as "1234.56" without going through float.
     */
    public static function parse(string $amount, ?string $currency = null): self
    {
        $amount = trim($amount);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        $minor = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return self::of($m[1] === '-' ? -$minor : $minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    /**
     * Percentage in basis points (1800 = 18%), rounded half up.
     */
    public function percentage(int $basisPoints): self
    {
        $raw = $this->minor * $basisPoints;
        $rounded = intdiv($raw + ($raw >= 0 ? 5000 : -5000), 10000);

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
            $share = intdiv($this->minor * $ratio, $total);
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
        $abs = abs($this->minor);

        return ($this->minor < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function format(): string
    {
        $abs = abs($this->minor);

        return ($this->minor < 0 ? '-' : '').number_format(intdiv($abs, 100)).'.'
            .str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT).' '.$this->currency;
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

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
