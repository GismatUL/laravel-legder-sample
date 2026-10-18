<?php

namespace App\Exceptions;

use App\Enums\InvoiceStatus;
use App\Support\Money;

class BillingException extends DomainRuleException
{
    public static function invalidTransition(InvoiceStatus $from, string $action): self
    {
        return new self("Cannot {$action} an invoice that is {$from->getLabel()}.", 'billing.invalid_transition', 409);
    }

    public static function emptyInvoice(): self
    {
        return new self('An invoice needs at least one line with a positive amount.', 'billing.empty_invoice');
    }

    public static function invalidAmount(): self
    {
        return new self('Payment amount must be positive.', 'billing.invalid_amount');
    }

    public static function overpayment(Money $amount, Money $outstanding): self
    {
        return new self("Payment of {$amount} exceeds the outstanding balance of {$outstanding}.", 'billing.overpayment');
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self("Idempotency key [{$key}] was already used for a different payment.", 'billing.idempotency_conflict', 409);
    }

    public static function hasPayments(): self
    {
        return new self('An invoice with payments cannot be voided.', 'billing.has_payments', 409);
    }
}
