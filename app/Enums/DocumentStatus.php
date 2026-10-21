<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Valid = 'valid';
    case Expiring = 'expiring'; // "Expiring soon"
    case Expired = 'expired';
    case Missing = 'missing';

    /**
     * Most urgent first: expired, missing, expiring, valid.
     */
    public function urgency(): int
    {
        return match ($this) {
            self::Expired => 0,
            self::Missing => 1,
            self::Expiring => 2,
            self::Valid => 3,
        };
    }

    public function needsAttention(): bool
    {
        return $this !== self::Valid;
    }
}
