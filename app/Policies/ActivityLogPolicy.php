<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;

/**
 * Everyone may read the audit trail (a viewer is often the auditor); nobody may change it.
 */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ActivityLog $entry): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ActivityLog $entry): bool
    {
        return false;
    }

    public function delete(User $user, ActivityLog $entry): bool
    {
        return false;
    }
}
