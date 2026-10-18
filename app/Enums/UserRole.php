<?php

namespace App\Enums;

use App\Support\TokenAbility;
use Filament\Support\Contracts\HasLabel;

/**
 * Single-organization access levels. Every role can read all financial data
 * of the organization; the roles differ in what they can change.
 *
 * - Viewer: read only (auditor, manager).
 * - Accountant: day-to-day bookkeeping: customers, invoices, payments,
 *   manual journal entries and reversals, bank statement imports.
 * - Admin: everything an accountant can do, plus the chart of accounts.
 */
enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Viewer = 'viewer';

    public function canBookkeep(): bool
    {
        return $this !== self::Viewer;
    }

    public function canManageChartOfAccounts(): bool
    {
        return $this === self::Admin;
    }

    /**
     * The most an API token of this role may be granted.
     *
     * @return list<string>
     */
    public function tokenAbilities(): array
    {
        return $this->canBookkeep() ? TokenAbility::all() : [TokenAbility::READ];
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
