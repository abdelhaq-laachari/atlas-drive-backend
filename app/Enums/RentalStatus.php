<?php

namespace App\Enums;

/**
 * Stored statuses. "Overdue" is not stored: it is an active rental past its return time + grace.
 */
enum RentalStatus: string
{
    case Reserved = 'reserved';
    case Active = 'active'; // car handed over
    case Completed = 'completed'; // car returned
    case Cancelled = 'cancelled';

    /**
     * Reserved and active rentals block their car.
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::Reserved, self::Active];
    }
}
