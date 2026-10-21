<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleDamage;

/**
 * Car damage record. Staff record damage and mark it repaired (daily work);
 * only the owner reopens or deletes a record (mistakes). Agency isolation: BelongsToAgency.
 */
class VehicleDamagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, VehicleDamage $damage): bool
    {
        return true;
    }

    public function repair(User $user, VehicleDamage $damage): bool
    {
        return true;
    }

    public function reopen(User $user, VehicleDamage $damage): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, VehicleDamage $damage): bool
    {
        return $user->isOwner();
    }
}
