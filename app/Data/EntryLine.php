<?php

namespace App\Data;

use App\Models\Account;
use App\Support\Money;

final readonly class EntryLine
{
    public function __construct(
        public Account $account,
        public int $debit = 0,
        public int $credit = 0,
        public ?string $memo = null,
    ) {}

    public static function debit(Account $account, Money $amount, ?string $memo = null): self
    {
        return new self($account, debit: $amount->minor, memo: $memo);
    }

    public static function credit(Account $account, Money $amount, ?string $memo = null): self
    {
        return new self($account, credit: $amount->minor, memo: $memo);
    }
}
