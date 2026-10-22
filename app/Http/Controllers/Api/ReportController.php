<?php

namespace App\Http\Controllers\Api;

use App\Enums\DamageStatus;
use App\Enums\MaintenanceStatus;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceJob;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Money reports (owner only). Periods are agency days; default: the last 30 days.
 */
class ReportController extends Controller
{
    /**
     * GET /api/reports/maintenance-costs?from=2026-10-01&to=2026-10-31
     * Garage jobs finished in the period + damage repairs done in the period, per car and per month.
     */
    public function maintenanceCosts(Request $request): JsonResponse
    {
        Gate::authorize('viewReports');
        [$from, $to, $timezone] = $this->period($request);

        $jobs = MaintenanceJob::query()
            ->with('vehicle')
            ->where('status', MaintenanceStatus::Done)
            ->whereNotNull('cost')
            ->where('completed_at', '>=', $from->startOfDay())
            ->where('completed_at', '<', $to->addDay()->startOfDay())
            ->get()
            ->map(fn (MaintenanceJob $job) => [
                'vehicle' => $job->vehicle,
                'month' => $job->completed_at->setTimezone($timezone)->format('Y-m'),
                'maintenance' => $job->cost,
                'damage_repairs' => 0,
            ]);

        $repairs = VehicleDamage::query()
            ->with('vehicle')
            ->where('status', DamageStatus::Repaired)
            ->whereNotNull('repair_cost')
            ->whereBetween('repaired_at', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (VehicleDamage $damage) => [
                'vehicle' => $damage->vehicle,
                'month' => $damage->repaired_at->format('Y-m'),
                'maintenance' => 0,
                'damage_repairs' => $damage->repair_cost,
            ]);

        $lines = $jobs->concat($repairs);

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->sums($lines),
            'by_vehicle' => $lines
                ->groupBy(fn ($line) => $line['vehicle']->id)
                ->map(fn (Collection $group) => [
                    'vehicle' => $this->vehicle($group->first()['vehicle']),
                    ...$this->sums($group),
                ])
                ->sortByDesc(fn ($row) => Money::cents($row['total']))
                ->values(),
            'by_month' => $lines
                ->groupBy('month')
                ->map(fn (Collection $group, string $month) => ['month' => $month, ...$this->sums($group)])
                ->sortKeys()
                ->values(),
        ]]);
    }

    /**
     * GET /api/reports/damages?from=&to=
     * Damage found during rentals in the period: what customers were charged vs what the repair cost.
     */
    public function damages(Request $request): JsonResponse
    {
        Gate::authorize('viewReports');
        [$from, $to, $timezone] = $this->period($request);

        $damages = VehicleDamage::query()
            ->with(['vehicle', 'rental.customer', 'charges'])
            ->whereNotNull('rental_id')
            ->where('found_at', '>=', $from->startOfDay())
            ->where('found_at', '<', $to->addDay()->startOfDay())
            ->orderBy('found_at')
            ->get();

        $rows = $damages->map(function (VehicleDamage $damage) use ($timezone) {
            $charged = Money::sum($damage->charges->pluck('amount'));
            $repaired = $damage->status === DamageStatus::Repaired && $damage->repair_cost !== null;

            return [
                'id' => $damage->id,
                'vehicle' => $this->vehicle($damage->vehicle),
                'rental_code' => $damage->rental?->rental_code,
                'customer' => $damage->rental?->customer?->full_name,
                'area' => $damage->area,
                'type' => $damage->type,
                'found_at' => $damage->found_at->setTimezone($timezone)->toIso8601String(),
                'status' => $damage->status,
                'charged' => $charged,
                'decision' => $damage->charges->first()?->decision,
                'repair_cost' => $repaired ? $damage->repair_cost : null,
                // + = the customer paid more than the repair; - = the agency lost money
                'difference' => $repaired ? Money::sum([$charged, -1 * (float) $damage->repair_cost]) : null,
            ];
        });

        $repairedRows = $rows->whereNotNull('repair_cost');

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'damages' => $rows->count(),
                'not_repaired_yet' => $rows->whereNull('repair_cost')->count(),
                'charged' => Money::sum($rows->pluck('charged')),
                'repair_cost' => Money::sum($repairedRows->pluck('repair_cost')),
                // Only damage already repaired (otherwise the cost isn't known yet)
                'difference' => Money::sum($repairedRows->pluck('difference')),
            ],
            'damages' => $rows->values(),
        ]]);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable, string}
     */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timezone = $request->user()->agency->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'], $timezone) : $today;
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'], $timezone) : $to->subDays(29);

        return [$from, $to, $timezone];
    }

    /**
     * @param  Collection<int, array{maintenance: mixed, damage_repairs: mixed}>  $lines
     * @return array{maintenance: string, damage_repairs: string, total: string}
     */
    private function sums(Collection $lines): array
    {
        $maintenance = Money::sum($lines->pluck('maintenance'));
        $repairs = Money::sum($lines->pluck('damage_repairs'));

        return ['maintenance' => $maintenance, 'damage_repairs' => $repairs, 'total' => Money::sum([$maintenance, $repairs])];
    }

    /**
     * @return array{id: int, fleet_code: string, make: string, model: string}
     */
    private function vehicle(Vehicle $vehicle): array
    {
        return ['id' => $vehicle->id, 'fleet_code' => $vehicle->fleet_code, 'make' => $vehicle->make, 'model' => $vehicle->model];
    }
}
