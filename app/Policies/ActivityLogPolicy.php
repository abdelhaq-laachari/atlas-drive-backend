<?php

namespace App\Policies;

use App\Models\User;

/**
 * Only the owner reads the footprints. Nobody edits or deletes them (see ActivityLog).
 */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }
}
