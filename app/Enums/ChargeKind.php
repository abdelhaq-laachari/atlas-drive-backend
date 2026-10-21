<?php

namespace App\Enums;

enum ChargeKind: string
{
    case Rental = 'rental'; // days x daily price
    case Extension = 'extension';
    case Late = 'late';
    case ExtraKm = 'extra_km';
    case Fuel = 'fuel';
    case Damage = 'damage';
    case Cancellation = 'cancellation'; // optional fee when a reservation is cancelled
    case Extra = 'extra'; // options: child seat, GPS... (prices still to agree with the client)
}
