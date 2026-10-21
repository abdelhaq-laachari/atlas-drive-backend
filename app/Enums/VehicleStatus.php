<?php

namespace App\Enums;

enum VehicleStatus: string
{
    case Available = 'available';
    case Rented = 'rented';
    case Reserved = 'reserved';
    case Maintenance = 'maintenance';
    case OutOfService = 'out-of-service';

    /**
     * Statuses staff may set by hand. Rented and Reserved are set by rentals.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Available, self::Maintenance, self::OutOfService];
    }

    public function isManual(): bool
    {
        return in_array($this, self::manual(), true);
    }
}
