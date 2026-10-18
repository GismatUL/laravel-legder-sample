<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function acceptsPayments(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }

    /**
     * The invoice state machine. "Overdue" is not a state: it is derived from
     * the due date of an Issued or PartiallyPaid invoice. Void is the
     * cancelled state; it is reachable only before any payment, because a
     * paid invoice would need a refund workflow, which this sample doesn't have.
     *
     *   Draft ──issue──► Issued ──pay──► PartiallyPaid ──pay──► Paid
     *                      │  └────────────pay (full)──────────►┘
     *                      └──void──► Void
     */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Draft => [self::Issued],
            self::Issued => [self::PartiallyPaid, self::Paid, self::Void],
            self::PartiallyPaid => [self::PartiallyPaid, self::Paid],
            self::Paid, self::Void => [],
        }, true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PartiallyPaid => 'Partially paid',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Void => 'danger',
        };
    }
}
