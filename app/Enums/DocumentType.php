<?php

namespace App\Enums;

/**
 * Documents tracked for every car. One current document per type; renewing keeps the old one as history.
 */
enum DocumentType: string
{
    case Insurance = 'insurance';
    case Inspection = 'inspection'; // technical inspection (visite technique)
    case Vignette = 'vignette'; // annual road tax
    case Registration = 'registration'; // registration card (carte grise), no expiry

    public function label(): string
    {
        return match ($this) {
            self::Insurance => 'Insurance',
            self::Inspection => 'Technical inspection',
            self::Vignette => 'Road tax (vignette)',
            self::Registration => 'Registration card',
        };
    }

    /**
     * Driving without these is illegal: an expired one blocks the hand-over.
     */
    public function isRequiredToDrive(): bool
    {
        return in_array($this, [self::Insurance, self::Inspection], true);
    }

    public function hasExpiry(): bool
    {
        return $this !== self::Registration;
    }
}
