<?php

namespace App\Policies;

use App\Models\MaintenancePlan;
use App\Models\User;

/**
 * Service plans: everyone sees and sets them up; only the owner deletes one.
 */
class MaintenancePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MaintenancePlan $plan): bool
    {
        return true;
    }

    public function delete(User $user, MaintenancePlan $plan): bool
    {
        return $user->isOwner();
    }
}
