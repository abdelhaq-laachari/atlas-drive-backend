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

    /**
     * No suggested price (return facts): 0 = waive ("no charge"), anything else = charge.
     */
    public static function for(int|float|string|null $suggested, int|float|string $amount): self
    {
        $amount = Money::cents($amount);

        if ($suggested === null) {
            return $amount === 0 ? self::Waive : self::Charge;
        }

        $suggested = Money::cents($suggested);

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
