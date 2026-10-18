<?php

namespace App\Exceptions;

use App\Support\Money;
use DomainException;

class LedgerException extends DomainException
{
    public static function immutable(): self
    {
        return new self('Posted journal entries cannot be changed or deleted. Post a reversing entry instead.');
    }

    public static function tooFewLines(): self
    {
        return new self('A journal entry needs at least two lines.');
    }

    public static function invalidLine(string $accountCode): self
    {
        return new self("Line for account {$accountCode} must have either a positive debit or a positive credit, not both.");
    }

    public static function inactiveAccount(string $accountCode): self
    {
        return new self("Account {$accountCode} is inactive.");
    }

    public static function unbalanced(Money $debits, Money $credits): self
    {
        return new self("Entry is unbalanced: debits {$debits} vs credits {$credits}.");
    }

    public static function alreadyReversed(string $reference): self
    {
        return new self("Entry {$reference} has already been reversed.");
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self("Idempotency key [{$key}] was already used for a different entry.");
    }
}
