<?php

namespace App\Policies;

use App\Models\BankTransaction;
use App\Models\User;

class BankTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BankTransaction $transaction): bool
    {
        return true;
    }

    /** Importing a bank statement. */
    public function create(User $user): bool
    {
        return $user->role->canBookkeep();
    }

    public function update(User $user, BankTransaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, BankTransaction $transaction): bool
    {
        return false;
    }
}
