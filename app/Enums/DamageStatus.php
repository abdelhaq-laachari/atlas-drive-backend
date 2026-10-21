<?php

namespace App\Enums;

enum DamageStatus: string
{
    case Open = 'open'; // on the car now, shown on the drawing
    case Repaired = 'repaired'; // kept as history
}
