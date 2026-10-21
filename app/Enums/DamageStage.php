<?php

namespace App\Enums;

/**
 * When the damage was found. Null = found outside a rental (e.g. inspection at the agency).
 */
enum DamageStage: string
{
    case Handover = 'handover'; // already there when the car left
    case Return = 'return'; // new when the car came back
}
