<?php

namespace Database\Seeders;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\Agency;
use App\Models\MaintenanceJob;
use App\Models\MaintenancePlan;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Sample garage work (like the Vue prototype's dashboard):
 *   V-009 at the garage now (hybrid battery check), V-004 oil change in 2 days,
 *   V-008 tyres in 5 days, V-002 oil change done last week (450 MAD).
 * Plans: every car has "oil change every 10,000 km or 12 months"; V-004 is due soon, V-005 overdue,
 * V-008 has "tyres every 40,000 km".
 */
class MaintenanceSeeder extends Seeder
{
    public function run(Agency $agency): void
    {
        $today = CarbonImmutable::now($agency->timezone)->startOfDay();
        $car = fn (int $number) => Vehicle::withoutGlobalScopes()->where('agency_id', $agency->id)->where('fleet_number', $number)->firstOrFail();

        // Service plans: started some time ago so a few cars are due
        foreach (Vehicle::withoutGlobalScopes()->where('agency_id', $agency->id)->get() as $vehicle) {
            [$monthsAgo, $kmAgo] = match ($vehicle->fleet_number) {
                4 => [11, 9600],   // due soon (400 km left)
                5 => [13, 6000],   // overdue (13 months)
                default => [4, 3500],
            };
            $this->plan($agency, $vehicle, MaintenanceType::OilChange, 10000, 12, $today->subMonths($monthsAgo), $vehicle->mileage - $kmAgo);
        }
        $this->plan($agency, $car(8), MaintenanceType::Tyres, 40000, null, $today->subYear(), $car(8)->mileage - 39400);

        $this->job($agency, $car(9), MaintenanceType::Repair, MaintenanceStatus::InProgress, [
            'garage' => 'Hybrid Service Casablanca',
            'description' => 'Waiting for a hybrid battery check at the dealer.',
            'started_at' => $today->subDay()->setTime(9, 0),
        ]);
        $this->job($agency, $car(4), MaintenanceType::OilChange, MaintenanceStatus::Scheduled, [
            'scheduled_for' => $today->addDays(2)->toDateString(),
            'garage' => 'Garage Atlas',
        ]);
        $this->job($agency, $car(8), MaintenanceType::Tyres, MaintenanceStatus::Scheduled, [
            'scheduled_for' => $today->addDays(5)->toDateString(),
            'description' => 'Replace the two front tyres.',
        ]);
        $this->job($agency, $car(2), MaintenanceType::OilChange, MaintenanceStatus::Done, [
            'garage' => 'Garage Atlas',
            'started_at' => $today->subDays(6)->setTime(9, 0),
            'completed_at' => $today->subDays(6)->setTime(12, 30),
            'mileage' => $car(2)->mileage - 1200,
            'cost' => 450,
            'description' => 'Oil 5W30 + filter.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function job(Agency $agency, Vehicle $vehicle, MaintenanceType $type, MaintenanceStatus $status, array $details): void
    {
        $job = new MaintenanceJob;
        $job->agency_id = $agency->id; // seeders run with nobody logged in
        $job->vehicle()->associate($vehicle);
        $job->type = $type;
        $job->status = $status;
        $job->forceFill($details);
        $job->save();
    }

    private function plan(Agency $agency, Vehicle $vehicle, MaintenanceType $type, ?int $everyKm, ?int $everyMonths, CarbonImmutable $start, int $startKm): void
    {
        $plan = new MaintenancePlan([
            'type' => $type,
            'every_km' => $everyKm,
            'every_months' => $everyMonths,
            'start_date' => $start->toDateString(),
            'start_mileage' => max(0, $startKm),
        ]);
        $plan->agency_id = $agency->id;
        $plan->vehicle()->associate($vehicle);
        $plan->save();
    }
}
