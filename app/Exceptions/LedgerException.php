<?php

namespace App\Exceptions;

use App\Support\Money;

class LedgerException extends DomainRuleException
{
    public static function immutable(): self
    {
        return new self('Posted journal entries cannot be changed or deleted. Post a reversing entry instead.', 'ledger.immutable', 409);
    }

    public static function tooFewLines(): self
    {
        return new self('A journal entry needs at least two lines.', 'ledger.too_few_lines');
    }

    public static function invalidLine(string $accountCode): self
    {
        return new self("Line for account {$accountCode} must have either a positive debit or a positive credit, not both.", 'ledger.invalid_line');
    }

    public static function inactiveAccount(string $accountCode): self
    {
        return new self("Account {$accountCode} is inactive.", 'ledger.inactive_account');
    }

    public static function unknownAccount(int $accountId): self
    {
        return new self("Account #{$accountId} does not exist.", 'ledger.unknown_account');
    }

    public static function controlAccount(string $accountCode): self
    {
        return new self("Account {$accountCode} is a control account and only accepts postings from its documents (invoices, payments).", 'ledger.control_account');
    }

    public static function accountHasPostings(string $accountCode): self
    {
        return new self("Account {$accountCode} has postings, so its code and type can no longer change.", 'ledger.account_has_postings', 409);
    }

    public static function unbalanced(Money $debits, Money $credits): self
    {
        return new self("Entry is unbalanced: debits {$debits} vs credits {$credits}.", 'ledger.unbalanced');
    }

    public static function alreadyReversed(string $reference): self
    {
        return new self("Entry {$reference} has already been reversed.", 'ledger.already_reversed', 409);
    }

    public static function documentEntry(string $reference): self
    {
        return new self("Entry {$reference} was created by a document. Reverse it from the document instead (e.g. void the invoice).", 'ledger.document_entry', 409);
    }

    public static function reversalOfReversal(string $reference): self
    {
        return new self("Entry {$reference} is itself a reversal and cannot be reversed.", 'ledger.reversal_of_reversal', 409);
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self("Idempotency key [{$key}] was already used for a different entry.", 'ledger.idempotency_conflict', 409);
    }
}
