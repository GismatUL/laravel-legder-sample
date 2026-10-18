<?php

namespace App\Exceptions;

use App\Support\Money;

class BankingException extends DomainRuleException
{
    public static function alreadyReconciled(): self
    {
        return new self('This bank line is already reconciled.', 'banking.already_reconciled', 409);
    }

    public static function notIncoming(): self
    {
        return new self('Only incoming bank lines can be matched to customer payments.', 'banking.not_incoming');
    }

    public static function paymentAlreadyMatched(): self
    {
        return new self('This payment is already matched to another bank line.', 'banking.payment_already_matched', 409);
    }

    public static function amountMismatch(Money $bank, Money $payment): self
    {
        return new self("The bank line ({$bank}) and the payment ({$payment}) have different amounts.", 'banking.amount_mismatch');
    }
}
