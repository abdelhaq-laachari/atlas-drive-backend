<?php

namespace App\Enums;

enum Role: string
{
    // Everything, including agency settings, users, prices and waiving charges.
    case Owner = 'owner';

    // Daily work: rentals, hand-over, return, customers.
    case Staff = 'staff';
}
