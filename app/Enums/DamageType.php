<?php

namespace App\Enums;

enum DamageType: string
{
    case Scratch = 'scratch';
    case Dent = 'dent';
    case Crack = 'crack'; // crack / chip
    case Broken = 'broken'; // broken or missing part
    case Other = 'other';
}
