<?php

namespace App\Support;

use App\Models\Agency;
use App\Models\Rental;
use Carbon\CarbonImmutable;

/**
 * What happened to the car during the rental, measured at return: km over the allowance,
 * late time, fuel missing. No prices: the agent decides (charge X / no charge).
 * Price suggestions exist for later, only when the agency turns on "suggest_return_prices".
 */
class ReturnFacts
{
    public function __construct(private Agency $agency) {}

    /**
     * @return array{
     *     km: ?array{handover: int, return: int, driven: int, included: ?int, over: int},
     *     late: array{due_at: string, returned_at: string, minutes_late: int, beyond_grace: bool, text: string, extra_days: int},
     *     fuel: ?array{handover: int, return: int, missing_quarters: int},
     *     early: array{unused_days: int},
     *     suggested: ?array{extra_km: string, late: string, fuel: string}
     * }
     */
    public function for(Rental $rental, CarbonImmutable $returnedAt, ?int $mileage, ?int $fuel): array
    {
        $timezone = $this->agency->timezone;
        $pickup = $rental->pickup_at->setTimezone($timezone);
        $due = $rental->return_at->setTimezone($timezone);
        $back = $returnedAt->setTimezone($timezone);

        // ---- Km: allowance = km included per day x billed days (null = unlimited) ----
        $km = null;
        if ($mileage !== null && $rental->handover_mileage !== null) {
            $driven = max(0, $mileage - $rental->handover_mileage);
            $perDay = $rental->vehicle->included_km_per_day;
            $included = $perDay === null ? null : $perDay * $rental->days;

            $km = [
                'handover' => $rental->handover_mileage,
                'return' => $mileage,
                'driven' => $driven,
                'included' => $included,
                'over' => $included === null ? 0 : max(0, $driven - $included),
            ];
        }

        // ---- Late: past the planned return plus the grace period → the extra billed days ----
        $minutesLate = max(0, (int) floor(($back->getTimestamp() - $due->getTimestamp()) / 60));
        $beyondGrace = $minutesLate > $this->agency->late_grace_minutes;
        $extraDays = $beyondGrace ? max(1, RentalDays::between($pickup, $back) - $rental->days) : 0;

        // ---- Fuel: quarters missing compared with the hand-over ----
        $fuelFacts = null;
        if ($fuel !== null && $rental->handover_fuel !== null) {
            $fuelFacts = [
                'handover' => $rental->handover_fuel,
                'return' => $fuel,
                'missing_quarters' => max(0, $rental->handover_fuel - $fuel),
            ];
        }

        // ---- Early: days paid for but not used (information only) ----
        $usedDays = $back->lt($due) ? RentalDays::between($pickup, $back) : $rental->days;

        $facts = [
            'km' => $km,
            'late' => [
                'due_at' => $due->toIso8601String(),
                'returned_at' => $back->toIso8601String(),
                'minutes_late' => $minutesLate,
                'beyond_grace' => $beyondGrace,
                'text' => self::latenessText($minutesLate),
                'extra_days' => $extraDays,
            ],
            'fuel' => $fuelFacts,
            'early' => ['unused_days' => max(0, $rental->days - $usedDays)],
            'suggested' => null,
        ];

        if ($this->agency->suggest_return_prices) {
            $facts['suggested'] = [
                'extra_km' => Money::round(($km['over'] ?? 0) * (float) $rental->vehicle->extra_km_fee),
                'late' => Money::round($extraDays * (float) $rental->daily_price),
                'fuel' => Money::round(($fuelFacts['missing_quarters'] ?? 0) * (float) $this->agency->fuel_price_per_quarter),
            ];
        }

        return $facts;
    }

    /**
     * 200 → "3 h 20 late", 2160 → "1 day 12 h late", 0 → "on time".
     */
    public static function latenessText(int $minutes): string
    {
        if ($minutes === 0) {
            return 'on time';
        }

        if ($minutes < 24 * 60) {
            $hours = intdiv($minutes, 60);
            $rest = $minutes % 60;

            return ($hours ? "{$hours} h".($rest ? ' '.str_pad((string) $rest, 2, '0', STR_PAD_LEFT) : '') : "{$rest} min").' late';
        }

        $days = intdiv($minutes, 24 * 60);
        $hours = intdiv($minutes % (24 * 60), 60);

        return "{$days} ".($days === 1 ? 'day' : 'days').($hours ? " {$hours} h" : '').' late';
    }
}
