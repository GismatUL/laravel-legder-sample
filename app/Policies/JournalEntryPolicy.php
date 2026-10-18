<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->canBookkeep();
    }

    /** Posted entries are immutable for everyone; corrections are reversals. */
    public function update(User $user, JournalEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return false;
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->role->canBookkeep();
    }
}
