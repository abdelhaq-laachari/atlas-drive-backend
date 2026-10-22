<?php

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;

/**
 * Agency settings: everyone in the agency reads them (the app needs the rules), only the owner edits.
 */
class AgencyPolicy
{
    public function view(User $user, Agency $agency): bool
    {
        return $user->agency_id === $agency->id;
    }

    public function update(User $user, Agency $agency): bool
    {
        return $user->agency_id === $agency->id && $user->isOwner();
    }
}
