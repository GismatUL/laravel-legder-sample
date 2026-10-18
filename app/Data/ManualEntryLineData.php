<?php

namespace App\Data;

use App\Support\Money;

final readonly class ManualEntryLineData
{
    public function __construct(
        public int $accountId,
        public Money $debit,
        public Money $credit,
        public ?string $memo = null,
    ) {}

    /**
     * @param  array<string, mixed>  $line  account_id, debit, credit (decimal strings), memo
     */
    public static function fromInput(array $line): self
    {
        return new self(
            accountId: (int) $line['account_id'],
            debit: Money::parse(filled($line['debit'] ?? null) ? (string) $line['debit'] : '0'),
            credit: Money::parse(filled($line['credit'] ?? null) ? (string) $line['credit'] : '0'),
            memo: filled($line['memo'] ?? null) ? (string) $line['memo'] : null,
        );
    }
}
