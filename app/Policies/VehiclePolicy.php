<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

/**
 * Who may do what with cars. Agency isolation is handled by BelongsToAgency,
 * so these checks are only about the role.
 */
class VehiclePolicy
{
    /**
     * Everyone in the agency can see the fleet.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return true;
    }

    /**
     * Adding cars and changing details or prices: owner only.
     */
    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->isOwner();
    }

    /**
     * Staff may mark a car available / in maintenance / out of service.
     */
    public function changeStatus(User $user, Vehicle $vehicle): bool
    {
        return true;
    }

    /**
     * Archiving (sold / retired) and restoring: owner only.
     */
    public function archive(User $user, Vehicle $vehicle): bool
    {
        return $user->isOwner();
    }
}
