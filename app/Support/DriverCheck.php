<?php

namespace App\Support;

use App\Models\Agency;
use App\Models\Customer;
use Carbon\CarbonInterface;

/**
 * Can this customer drive on a given day (the pickup date)?
 * Rules from the agency settings: minimum age (21) and licence held for at least N years (2).
 * A blocked or archived customer cannot start a rental.
 */
class DriverCheck
{
    public function __construct(private Agency $agency) {}

    /**
     * @return array{eligible: bool, on: string, checks: list<array{key: string, ok: bool, value: ?int, minimum: ?int, message: string}>}
     */
    public function check(Customer $customer, CarbonInterface $on): array
    {
        $date = $on->toDateString();
        $minAge = $this->agency->driver_min_age;
        $minLicenceYears = $this->agency->licence_min_years;

        $age = self::fullYearsBetween($customer->birth_date->toDateString(), $date);

        $checks = [[
            'key' => 'age',
            'ok' => $age >= $minAge,
            'value' => $age,
            'minimum' => $minAge,
            'message' => "{$age} years old (minimum {$minAge})",
        ]];

        if ($customer->licence_issue_date) {
            $licenceYears = self::fullYearsBetween($customer->licence_issue_date->toDateString(), $date);
            $checks[] = [
                'key' => 'licence',
                'ok' => $licenceYears >= $minLicenceYears,
                'value' => $licenceYears,
                'minimum' => $minLicenceYears,
                'message' => "Licence held for {$licenceYears} ".($licenceYears === 1 ? 'year' : 'years')." (minimum {$minLicenceYears})",
            ];
        } else {
            $checks[] = [
                'key' => 'licence',
                'ok' => false,
                'value' => null,
                'minimum' => $minLicenceYears,
                'message' => 'No driving licence recorded',
            ];
        }

        if ($customer->isBlocked()) {
            $checks[] = [
                'key' => 'blocked',
                'ok' => false,
                'value' => null,
                'minimum' => null,
                'message' => 'Blocked: '.$customer->block_reason,
            ];
        }

        if ($customer->isArchived()) {
            $checks[] = [
                'key' => 'archived',
                'ok' => false,
                'value' => null,
                'minimum' => null,
                'message' => 'Archived customer',
            ];
        }

        return [
            'eligible' => collect($checks)->every('ok'),
            'on' => $date,
            'checks' => $checks,
        ];
    }

    /**
     * Full years from one 'YYYY-MM-DD' date to another (birthdays and licence anniversaries).
     * Same rule as the Vue prototype: you turn 21 on your birthday, not the day before.
     */
    public static function fullYearsBetween(string $from, string $to): int
    {
        [$fromYear, $fromMonth, $fromDay] = array_map('intval', explode('-', substr($from, 0, 10)));
        [$toYear, $toMonth, $toDay] = array_map('intval', explode('-', substr($to, 0, 10)));

        $beforeAnniversary = $toMonth < $fromMonth || ($toMonth === $fromMonth && $toDay < $fromDay);

        return $toYear - $fromYear - ($beforeAnniversary ? 1 : 0);
    }
}
