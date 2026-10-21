<?php

namespace App\Enums;

enum IdType: string
{
    case Cin = 'cin'; // Moroccan national ID card (CIN)
    case Passport = 'passport';
}
