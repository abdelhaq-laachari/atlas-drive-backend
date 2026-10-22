<?php

namespace App\Support;

use App\Enums\MaintenanceStatus;
use App\Models\Agency;
use App\Models\MaintenanceJob;
use App\Models\MaintenancePlan;
use Carbon\CarbonImmutable;

/**
 * When is each car's routine work next due? From the last finished job of that type
 * (or the plan's starting point), every X km and/or every Y months — whichever comes first.
 */
class MaintenanceSchedule
{
    public const SOON_DAYS = 14;

    public const SOON_KM = 1000;

    private CarbonImmutable $today;

    public function __construct(private Agency $agency)
    {
        $this->today = CarbonImmutable::now($agency->timezone)->startOfDay();
    }

    /**
     * The plan's vehicle with its maintenanceJobs relation should be loaded.
     *
     * @return array<string, mixed>
     */
    public function row(MaintenancePlan $plan): array
    {
        $vehicle = $plan->vehicle;
        $jobs = $vehicle->maintenanceJobs->where('type', $plan->type);

        // Starting point: the most recent of the last finished job and the plan's own start
        $lastDone = $jobs->where('status', MaintenanceStatus::Done)->sortByDesc('completed_at')->first();
        $planStart = CarbonImmutable::parse($plan->start_date->toDateString(), $this->agency->timezone);
        $jobDay = $lastDone?->completed_at?->setTimezone($this->agency->timezone)->startOfDay();

        [$baseDate, $baseKm, $baseFrom] = $jobDay && $jobDay->gte($planStart)
            ? [$jobDay, $lastDone->mileage ?? $plan->start_mileage, 'last_job']
            : [$planStart, $plan->start_mileage, 'plan_start'];

        $dueDate = $plan->every_months ? $baseDate->addMonthsNoOverflow($plan->every_months) : null;
        $dueKm = $plan->every_km ? $baseKm + $plan->every_km : null;
        $daysLeft = $dueDate ? (int) $this->today->diffInDays($dueDate, false) : null;
        $kmLeft = $dueKm !== null ? $dueKm - $vehicle->mileage : null;

        $status = match (true) {
            ($daysLeft !== null && $daysLeft < 0) || ($kmLeft !== null && $kmLeft <= 0) => 'overdue',
            ($daysLeft !== null && $daysLeft <= self::SOON_DAYS) || ($kmLeft !== null && $kmLeft <= self::SOON_KM) => 'due_soon',
            default => 'ok',
        };

        /** @var MaintenanceJob|null $planned */
        $planned = $jobs->whereIn('status', [MaintenanceStatus::Scheduled, MaintenanceStatus::InProgress])->sortBy('id')->first();

        return [
            'plan_id' => $plan->id,
            'vehicle' => [
                'id' => $vehicle->id,
                'fleet_code' => $vehicle->fleet_code,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'mileage' => $vehicle->mileage,
            ],
            'type' => $plan->type,
            'type_label' => $plan->type->label(),
            'every_km' => $plan->every_km,
            'every_months' => $plan->every_months,
            'last_done' => ['date' => $baseDate->toDateString(), 'mileage' => $baseKm, 'from' => $baseFrom],
            'next_due' => ['date' => $dueDate?->toDateString(), 'mileage' => $dueKm],
            'days_left' => $daysLeft,
            'km_left' => $kmLeft,
            'status' => $status,
            'planned_job' => $planned ? [
                'id' => $planned->id,
                'status' => $planned->status,
                'scheduled_for' => $planned->scheduled_for?->toDateString(),
            ] : null,
            'notes' => $plan->notes,
        ];
    }

    /**
     * Most urgent first: overdue, due soon, ok; then the soonest.
     *
     * @param  iterable<MaintenancePlan>  $plans
     * @return list<array<string, mixed>>
     */
    public function rows(iterable $plans): array
    {
        $urgency = ['overdue' => 0, 'due_soon' => 1, 'ok' => 2];

        return collect($plans)
            ->map(fn (MaintenancePlan $plan) => $this->row($plan))
            ->sortBy([
                fn ($a, $b) => $urgency[$a['status']] <=> $urgency[$b['status']],
                fn ($a, $b) => min($a['days_left'] ?? PHP_INT_MAX, intdiv($a['km_left'] ?? PHP_INT_MAX, 50))
                    <=> min($b['days_left'] ?? PHP_INT_MAX, intdiv($b['km_left'] ?? PHP_INT_MAX, 50)),
            ])
            ->values()
            ->all();
    }
}
