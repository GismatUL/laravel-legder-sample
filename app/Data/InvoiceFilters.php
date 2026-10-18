<?php

namespace App\Data;

use App\Enums\InvoiceStatus;

final readonly class InvoiceFilters
{
    public function __construct(
        public ?InvoiceStatus $status = null,
        public ?int $customerId = null,
        public bool $overdueOnly = false,
        public ?string $number = null,
    ) {}
}
