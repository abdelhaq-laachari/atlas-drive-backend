<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleDocument;

/**
 * Car paperwork. Staff record and renew documents (daily work); only the owner deletes one.
 * Agency isolation is handled by BelongsToAgency.
 */
class VehicleDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, VehicleDocument $document): bool
    {
        return true;
    }

    public function delete(User $user, VehicleDocument $document): bool
    {
        return $user->isOwner();
    }
}
