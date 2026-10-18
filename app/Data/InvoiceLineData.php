<?php

namespace App\Data;

use App\Support\Money;

final readonly class InvoiceLineData
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitPrice,
        public int $taxRateBasisPoints,
    ) {}

    /**
     * @param  array<string, mixed>  $line  description, quantity, unit_price (decimal string), tax_rate_bp
     */
    public static function fromInput(array $line): self
    {
        return new self(
            description: (string) $line['description'],
            quantity: (int) $line['quantity'],
            unitPrice: Money::parse((string) $line['unit_price']),
            taxRateBasisPoints: (int) $line['tax_rate_bp'],
        );
    }
}
