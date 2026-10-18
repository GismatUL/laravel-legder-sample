<?php

namespace App\Data;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final readonly class InvoiceData
{
    /**
     * @param  list<InvoiceLineData>  $lines
     */
    public function __construct(
        public int $customerId,
        public ?CarbonInterface $dueDate,
        public ?string $notes,
        public array $lines,
    ) {}

    /**
     * Built from already validated input (API request or admin form).
     * Lines may be keyed (Filament repeaters key items by UUID), so they are re-indexed.
     *
     * @param  array<string, mixed>  $input  customer_id, due_date, notes, lines[]
     */
    public static function fromInput(array $input): self
    {
        return new self(
            customerId: (int) $input['customer_id'],
            dueDate: filled($input['due_date'] ?? null) ? Carbon::parse((string) $input['due_date']) : null,
            notes: filled($input['notes'] ?? null) ? (string) $input['notes'] : null,
            lines: array_values(array_map(
                fn (array $line) => InvoiceLineData::fromInput($line),
                (array) $input['lines'],
            )),
        );
    }
}
