<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Account $account): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->canManageChartOfAccounts();
    }

    public function update(User $user, Account $account): bool
    {
        return $user->role->canManageChartOfAccounts();
    }

    /** Accounts are deactivated, never deleted: past entries reference them. */
    public function delete(User $user, Account $account): bool
    {
        return false;
    }
}
