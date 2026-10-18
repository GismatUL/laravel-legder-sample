<?php

namespace App\Exceptions;

use App\Enums\InvoiceStatus;
use App\Support\Money;
use Carbon\CarbonInterface;

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

    public static function paymentBeforeIssue(CarbonInterface $issueDate): self
    {
        return new self("A payment can't be dated before the invoice issue date ({$issueDate->toDateString()}).", 'billing.payment_before_issue');
    }

    public static function paymentInFuture(): self
    {
        return new self("A payment can't be dated in the future.", 'billing.payment_in_future');
    }

    public static function dueBeforeIssue(CarbonInterface $dueDate): self
    {
        return new self("The due date ({$dueDate->toDateString()}) is before the issue date. Change the due date first.", 'billing.due_before_issue');
    }

    public static function hasPayments(): self
    {
        return new self('An invoice with payments cannot be voided.', 'billing.has_payments', 409);
    }
}
