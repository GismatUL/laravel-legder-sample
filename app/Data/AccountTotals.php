<?php

namespace App\Data;

use App\Enums\AccountType;

final readonly class AccountTotals
{
    public function __construct(
        public string $code,
        public string $name,
        public AccountType $type,
        public int $debits,
        public int $credits,
    ) {}
}
