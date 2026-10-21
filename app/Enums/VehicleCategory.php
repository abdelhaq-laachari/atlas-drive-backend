<?php

namespace App\Enums;

enum VehicleCategory: string
{
    case Economy = 'economy';
    case Compact = 'compact';
    case Suv = 'suv';
    case Van = 'van';
    case Luxury = 'luxury';
}
