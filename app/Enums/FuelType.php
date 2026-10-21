<?php

namespace App\Enums;

enum FuelType: string
{
    case Diesel = 'diesel';
    case Petrol = 'petrol';
    case Hybrid = 'hybrid';
    case Electric = 'electric';
}
