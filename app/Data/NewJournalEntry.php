<?php

namespace App\Data;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

final readonly class NewJournalEntry
{
    /**
     * @param  list<EntryLine>  $lines
     */
    public function __construct(
        public CarbonInterface $date,
        public string $description,
        public array $lines,
        public ?string $idempotencyKey = null,
        public ?Model $source = null,
    ) {}
}
