<?php

namespace App\Enums;

/**
 * Garage work only. Insurance, technical inspection and vignette are documents (see DocumentType).
 */
enum MaintenanceType: string
{
    case OilChange = 'oil_change'; // vidange
    case Tyres = 'tyres';
    case Brakes = 'brakes';
    case Battery = 'battery';
    case Service = 'service'; // general service (révision)
    case Repair = 'repair'; // mechanical repair
    case Bodywork = 'bodywork'; // carrosserie
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OilChange => 'Oil change',
            self::Tyres => 'Tyres',
            self::Brakes => 'Brakes',
            self::Battery => 'Battery',
            self::Service => 'General service',
            self::Repair => 'Repair',
            self::Bodywork => 'Bodywork',
            self::Other => 'Other',
        };
    }
}
