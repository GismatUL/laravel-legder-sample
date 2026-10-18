<?php

namespace App\Exceptions;

use App\Enums\InvoiceStatus;
use App\Support\Money;
use DomainException;

class BillingException extends DomainException
{
    public static function invalidTransition(InvoiceStatus $from, string $action): self
    {
        return new self("Cannot {$action} an invoice that is {$from->getLabel()}.");
    }

    public static function emptyInvoice(): self
    {
        return new self('An invoice needs at least one line with a positive amount.');
    }

    public static function invalidAmount(): self
    {
        return new self('Payment amount must be positive.');
    }

    public static function overpayment(Money $amount, Money $outstanding): self
    {
        return new self("Payment of {$amount} exceeds the outstanding balance of {$outstanding}.");
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self("Idempotency key [{$key}] was already used for a different payment.");
    }

    public static function hasPayments(): self
    {
        return new self('An invoice with payments cannot be voided.');
    }
}
