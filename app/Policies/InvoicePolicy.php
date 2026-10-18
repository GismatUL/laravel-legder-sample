<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Who may act on invoices. Whether the invoice's state allows the action
 * (only drafts can be edited, paid invoices can't be voided) is a business
 * rule, checked by InvoiceService, so the API answers 409 for it, not 403.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->canBookkeep();
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->role->canBookkeep();
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->role->canBookkeep();
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->role->canBookkeep();
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->role->canBookkeep();
    }

    public function recordPayment(User $user, Invoice $invoice): bool
    {
        return $user->role->canBookkeep();
    }
}
