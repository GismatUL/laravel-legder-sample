<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->canBookkeep();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->role->canBookkeep();
    }

    /** Customers with invoices must stay (restrictOnDelete); there is no delete workflow. */
    public function delete(User $user, Customer $customer): bool
    {
        return false;
    }
}
