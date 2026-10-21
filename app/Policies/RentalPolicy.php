<?php

namespace App\Policies;

use App\Models\Rental;
use App\Models\User;

/**
 * Rentals are daily work: every agency user may book, hand over, return and settle.
 * Discounts are allowed with a reason (logged); the owner may restrict this later (client question #3).
 */
class RentalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Rental $rental): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Rental $rental): bool
    {
        return true;
    }
}
