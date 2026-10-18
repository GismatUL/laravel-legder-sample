<?php

namespace App\Exceptions;

class MoneyException extends DomainRuleException
{
    public static function overflow(): self
    {
        return new self('The amount is too large to be represented.', 'money.overflow');
    }
}
