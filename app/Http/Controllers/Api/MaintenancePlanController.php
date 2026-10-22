<?php

namespace App\Http\Controllers\Api;

use App\Enums\MaintenanceType;
use App\Http\Controllers\Controller;
use App\Models\MaintenancePlan;
use App\Models\Vehicle;
use App\Support\MaintenanceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Service plans ("oil change every 10,000 km or 12 months") and what is due soon.
 */
class MaintenancePlanController extends Controller
{
    /**
     * GET /api/maintenance/upcoming?all=1 — the fleet's routine work, most urgent first.
     * Default: only overdue and due soon (dashboard card); all=1 also lists the ok ones.
     */
    public function upcoming(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MaintenancePlan::class);

        $plans = MaintenancePlan::query()
            ->whereHas('vehicle', fn ($query) => $query->inFleet())
            ->with(['vehicle.maintenanceJobs'])
            ->get();

        $rows = (new MaintenanceSchedule($request->user()->agency))->rows($plans);

        if (! $request->boolean('all')) {
            $rows = array_values(array_filter($rows, fn ($row) => $row['status'] !== 'ok'));
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/vehicles/{vehicle}/maintenance-plans
     */
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('viewAny', MaintenancePlan::class);

        $plans = MaintenancePlan::where('vehicle_id', $vehicle->id)->with(['vehicle.maintenanceJobs'])->get();

        return response()->json(['data' => (new MaintenanceSchedule($request->user()->agency))->rows($plans)]);
    }

    /**
     * POST /api/vehicles/{vehicle}/maintenance-plans
     * { type, every_km?, every_months?, start_date?, start_mileage?, notes? }
     * start_* = last time it was done before using the system (default: today and the car's km).
     */
    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('create', MaintenancePlan::class);

        abort_if($vehicle->isArchived(), 409, "{$vehicle->fleet_code} is archived.");

        $validated = $request->validate([
            'type' => ['required', Rule::enum(MaintenanceType::class)],
            ...$this->rules($request, $vehicle),
        ]);

        if (MaintenancePlan::where('vehicle_id', $vehicle->id)->where('type', $validated['type'])->exists()) {
            throw ValidationException::withMessages(['type' => "{$vehicle->fleet_code} already has a plan for this. Edit it instead."]);
        }

        $plan = new MaintenancePlan([
            ...$validated,
            'start_date' => $validated['start_date'] ?? CarbonImmutable::now($request->user()->agency->timezone)->toDateString(),
            'start_mileage' => $validated['start_mileage'] ?? $vehicle->mileage,
        ]);
        $plan->vehicle()->associate($vehicle);
        $plan->save();

        return $this->row($request, $plan, 201);
    }

    /**
     * PUT /api/maintenance-plans/{plan}  { every_km?, every_months?, start_date, start_mileage, notes? }
     */
    public function update(Request $request, MaintenancePlan $plan): JsonResponse
    {
        Gate::authorize('update', $plan);

        $validated = $request->validate($this->rules($request, $plan->vehicle, required: true));
        $plan->update($validated);

        return $this->row($request, $plan);
    }

    /**
     * DELETE /api/maintenance-plans/{plan} — owner only.
     */
    public function destroy(MaintenancePlan $plan): Response
    {
        Gate::authorize('delete', $plan);

        $plan->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function rules(Request $request, Vehicle $vehicle, bool $required = false): array
    {
        $today = CarbonImmutable::now($request->user()->agency->timezone)->toDateString();

        return [
            // At least one of the two; whichever comes first
            'every_km' => ['nullable', 'integer', 'between:500,200000', 'required_without:every_months'],
            'every_months' => ['nullable', 'integer', 'between:1,120', 'required_without:every_km'],
            'start_date' => [$required ? 'required' : 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'start_mileage' => [$required ? 'required' : 'nullable', 'integer', 'min:0', 'max:'.$vehicle->mileage],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function row(Request $request, MaintenancePlan $plan, int $status = 200): JsonResponse
    {
        $plan->load('vehicle.maintenanceJobs');

        return response()->json(['data' => (new MaintenanceSchedule($request->user()->agency))->row($plan)], $status);
    }
}
