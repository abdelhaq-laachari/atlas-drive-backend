<?php

namespace App\Policies;

use App\Models\User;

/**
 * Staff accounts are managed by the owner only, inside their own agency.
 */
class UserPolicy
{
    public function manageStaff(User $user): bool
    {
        return $user->isOwner() && $user->isActive();
    }
}
