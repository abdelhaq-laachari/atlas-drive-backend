<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Staff add and edit customers at the desk (daily work).
 * Only the owner blocks / unblocks and archives / restores. Agency isolation: BelongsToAgency.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return true;
    }

    public function block(User $user, Customer $customer): bool
    {
        return $user->isOwner();
    }

    public function archive(User $user, Customer $customer): bool
    {
        return $user->isOwner();
    }
}
