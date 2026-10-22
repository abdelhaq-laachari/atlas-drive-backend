<?php

namespace App\Policies;

use App\Models\MaintenanceJob;
use App\Models\User;

/**
 * Garage work is daily work: staff record, start and finish jobs. Only the owner deletes one.
 * Agency isolation: BelongsToAgency.
 */
class MaintenanceJobPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MaintenanceJob $job): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MaintenanceJob $job): bool
    {
        return true;
    }

    public function delete(User $user, MaintenanceJob $job): bool
    {
        return $user->isOwner();
    }
}
