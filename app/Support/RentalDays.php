<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Billed days (same rule as the Vue prototype's rentalDays):
 * calendar days, plus one more if the return time is later than the pickup time (minimum 1).
 * Uses the agency's local date and time, so a daylight-saving change never adds a day.
 */
class RentalDays
{
    public static function between(CarbonInterface $pickupLocal, CarbonInterface $returnLocal): int
    {
        $pickupDay = $pickupLocal->copy()->startOfDay();
        $returnDay = $returnLocal->copy()->setTimezone($pickupLocal->getTimezone())->startOfDay();

        $days = (int) round(($returnDay->getTimestamp() - $pickupDay->getTimestamp()) / 86400);
        $extraDay = $returnLocal->format('H:i') > $pickupLocal->format('H:i') ? 1 : 0;

        return max(1, $days + $extraDay);
    }
}
