<?php

namespace App\Enums;

use App\Support\Money;

/**
 * What the manager decided for a suggested amount. Reduce and waive need a reason.
 */
enum ChargeDecision: string
{
    case Charge = 'charge';
    case Reduce = 'reduce';
    case Waive = 'waive';

    public static function for(int|float|string $suggested, int|float|string $amount): self
    {
        $suggested = Money::cents($suggested);
        $amount = Money::cents($amount);

        return match (true) {
            $amount === 0 && $suggested > 0 => self::Waive,
            $amount < $suggested => self::Reduce,
            default => self::Charge,
        };
    }

    public function needsReason(): bool
    {
        return $this !== self::Charge;
    }
}
