<?php

namespace Tests\Feature\Rentals;

use App\Support\RentalDays;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RentalDaysTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function periods(): array
    {
        return [
            'same time 3 days later' => ['2026-11-10 10:00', '2026-11-13 10:00', 3],
            'returned 30 min later than pickup time: +1 day' => ['2026-11-10 10:00', '2026-11-13 10:30', 4],
            'returned earlier in the day than pickup time' => ['2026-11-10 10:00', '2026-11-13 09:00', 3],
            'same day, a few hours' => ['2026-11-10 09:00', '2026-11-10 18:00', 1],
            'overnight' => ['2026-11-10 18:00', '2026-11-11 09:00', 1],
            'one week' => ['2026-11-10 10:00', '2026-11-17 10:00', 7],
            // Morocco moves its clocks around Ramadan: still 7 days, not 6 or 8
            'across a clock change (Ramadan 2026)' => ['2026-02-14 10:00', '2026-02-21 10:00', 7],
            'across the clock change back' => ['2026-03-17 10:00', '2026-03-24 10:00', 7],
        ];
    }

    #[DataProvider('periods')]
    public function test_billed_days_follow_the_agency_rule(string $pickup, string $return, int $days): void
    {
        $pickupAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $pickup, 'Africa/Casablanca');
        $returnAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $return, 'Africa/Casablanca');

        $this->assertSame($days, RentalDays::between($pickupAt, $returnAt));
    }
}
