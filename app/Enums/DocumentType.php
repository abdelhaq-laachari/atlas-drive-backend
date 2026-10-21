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

    public function hasExpiry(): bool
    {
        return $this !== self::Registration;
    }
}
