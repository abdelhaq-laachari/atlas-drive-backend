<?php

namespace App\Http\Controllers\Api;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\RentalStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MaintenanceJobResource;
use App\Models\MaintenanceJob;
use App\Models\Rental;
use App\Models\Vehicle;
use App\Rules\WholeDirhams;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance jobs (garage work): scheduled → in progress (car "in maintenance") → done / cancelled.
 */
class MaintenanceJobController extends Controller
{
    /**
     * GET /api/maintenance?status=&vehicle_id=&type=&from=&to=&per_page=
     * from / to: agency days (the day it started, or its planned day if not started). Newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', MaintenanceJob::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(MaintenanceStatus::class)],
            'vehicle_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(MaintenanceType::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        // The day a job happened (started) or is planned, in the agency's own days
        $timezone = $request->user()->agency->timezone;

        $jobs = MaintenanceJob::query()
            ->with('vehicle')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['vehicle_id'] ?? null, fn ($query, $id) => $query->where('vehicle_id', $id))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where(fn ($query) => $query
                ->where('started_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay())
                ->orWhere(fn ($query) => $query->whereNull('started_at')->where('scheduled_for', '>=', $from))))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where(fn ($query) => $query
                ->where('started_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay())
                ->orWhere(fn ($query) => $query->whereNull('started_at')->where('scheduled_for', '<=', $to))))
            ->orderByRaw('COALESCE(started_at, scheduled_for) desc')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return MaintenanceJobResource::collection($jobs);
    }

    /**
     * POST /api/maintenance  { vehicle_id, type, scheduled_for?, garage?, description? }  → scheduled
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', MaintenanceJob::class);

        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            ...$this->detailRules(),
        ]);

        $vehicle = Vehicle::find($validated['vehicle_id']);
        if (! $vehicle) {
            throw ValidationException::withMessages(['vehicle_id' => 'Not found.']);
        }
        abort_if($vehicle->isArchived(), 409, "{$vehicle->fleet_code} is archived.");

        $job = new MaintenanceJob(collect($validated)->except(['vehicle_id', 'cost', 'mileage'])->all());
        $job->vehicle()->associate($vehicle);
        $job->save();

        return (new MaintenanceJobResource($job->load('vehicle')))->response()->setStatusCode(201);
    }

    /**
     * GET /api/maintenance/{job}
     */
    public function show(MaintenanceJob $job): MaintenanceJobResource
    {
        Gate::authorize('view', $job);

        return new MaintenanceJobResource($job->load('vehicle'));
    }

    /**
     * PUT /api/maintenance/{job}  { type, scheduled_for?, garage?, description?, cost?, mileage? }
     * Correct a job (cost and km can be corrected once it is done).
     */
    public function update(Request $request, MaintenanceJob $job): MaintenanceJobResource
    {
        Gate::authorize('update', $job);

        abort_if($job->status === MaintenanceStatus::Cancelled, 409, 'This job is cancelled.');

        $validated = $request->validate($this->detailRules());
        $job->update($validated);

        return new MaintenanceJobResource($job->load('vehicle'));
    }

    /**
     * POST /api/maintenance/{job}/start  { at? }  — the car goes to the garage: status "maintenance".
     */
    public function start(Request $request, MaintenanceJob $job): JsonResponse
    {
        Gate::authorize('update', $job);

        abort_unless($job->status === MaintenanceStatus::Scheduled, 409, 'Only a scheduled job can be started.');
        $at = $this->actionTime($request);

        $warnings = DB::transaction(function () use ($job, $at) {
            $vehicle = Vehicle::whereKey($job->vehicle_id)->lockForUpdate()->first();
            $this->ensureCarIsHere($vehicle);

            $job->status = MaintenanceStatus::InProgress;
            $job->started_at = $at;
            $job->save();

            $vehicle->status = VehicleStatus::Maintenance;
            $vehicle->save();

            return $this->bookingWarnings($vehicle);
        });

        return (new MaintenanceJobResource($job->load('vehicle')))
            ->additional(['meta' => ['warnings' => $warnings]])
            ->response();
    }

    /**
     * POST /api/maintenance/{job}/finish  { at?, mileage?, cost?, garage?, description? }
     * The work is done; the car comes back (Available, or Reserved if a booking is waiting).
     * A scheduled job can be finished directly (recording work already done).
     */
    public function finish(Request $request, MaintenanceJob $job): MaintenanceJobResource
    {
        Gate::authorize('update', $job);

        abort_unless($job->isOpen(), 409, 'This job is already '.$job->status->value.'.');

        $vehicle = $job->vehicle;
        $validated = $request->validate([
            'at' => ['nullable', 'date_format:Y-m-d H:i'],
            'mileage' => ['nullable', 'integer', 'min:'.$vehicle->mileage, 'max:2000000'],
            'cost' => ['nullable', new WholeDirhams],
            'garage' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'mileage.min' => "The km can't be lower than the car's current km ({$vehicle->mileage}).",
        ]);
        $at = $this->actionTime($request);

        if ($job->status === MaintenanceStatus::Scheduled) {
            $this->ensureCarIsHere($vehicle);
        }
        if ($job->started_at && $at->lt($job->started_at)) {
            throw ValidationException::withMessages(['at' => 'The end cannot be before the start.']);
        }

        DB::transaction(function () use ($job, $validated, $at) {
            $vehicle = Vehicle::whereKey($job->vehicle_id)->lockForUpdate()->first();

            $job->fill(collect($validated)->only(['mileage', 'cost', 'garage', 'description'])->filter(fn ($value) => $value !== null)->all());
            $job->status = MaintenanceStatus::Done;
            $job->started_at ??= $at;
            $job->completed_at = $at;
            $job->save();

            if (isset($validated['mileage'])) {
                $vehicle->mileage = max($vehicle->mileage, (int) $validated['mileage']);
                $vehicle->save();
            }

            $vehicle->releaseFromMaintenance();
        });

        return new MaintenanceJobResource($job->load('vehicle'));
    }

    /**
     * POST /api/maintenance/{job}/cancel  { reason }  — a started job also brings the car back.
     */
    public function cancel(Request $request, MaintenanceJob $job): MaintenanceJobResource
    {
        Gate::authorize('update', $job);

        abort_unless($job->isOpen(), 409, 'This job is already '.$job->status->value.'.');

        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        DB::transaction(function () use ($job, $validated) {
            $job->status = MaintenanceStatus::Cancelled;
            $job->cancel_reason = $validated['reason'];
            $job->save();

            $job->vehicle->releaseFromMaintenance();
        });

        return new MaintenanceJobResource($job->load('vehicle'));
    }

    /**
     * DELETE /api/maintenance/{job} — owner only (entered by mistake). Not while the car is at the garage.
     */
    public function destroy(MaintenanceJob $job): Response
    {
        Gate::authorize('delete', $job);

        abort_if($job->status === MaintenanceStatus::InProgress, 409, 'Finish or cancel this job first: the car is at the garage.');

        $job->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function detailRules(): array
    {
        return [
            'type' => ['required', Rule::enum(MaintenanceType::class)],
            'scheduled_for' => ['nullable', 'date_format:Y-m-d'],
            'garage' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost' => ['nullable', new WholeDirhams],
            'mileage' => ['nullable', 'integer', 'min:0', 'max:2000000'],
        ];
    }

    /**
     * The car can't go to the garage while a customer has it.
     */
    private function ensureCarIsHere(Vehicle $vehicle): void
    {
        $out = Rental::where('vehicle_id', $vehicle->id)->where('status', RentalStatus::Active)->first();

        abort_if($out, 409, "{$vehicle->fleet_code} is out with a customer ({$out?->rental_code}).");
        abort_if($vehicle->isArchived(), 409, "{$vehicle->fleet_code} is archived.");
    }

    /**
     * Reservations waiting for this car: staff should check the garage will be done in time.
     *
     * @return list<string>
     */
    private function bookingWarnings(Vehicle $vehicle): array
    {
        $timezone = $vehicle->agency->timezone;

        return Rental::where('vehicle_id', $vehicle->id)
            ->where('status', RentalStatus::Reserved)
            ->orderBy('pickup_at')
            ->get()
            ->map(fn (Rental $rental) => "{$vehicle->fleet_code} is booked from "
                .$rental->pickup_at->setTimezone($timezone)->format('d/m/Y H:i')." ({$rental->rental_code}).")
            ->all();
    }

    /**
     * Agency local time "Y-m-d H:i", or now; never in the future.
     */
    private function actionTime(Request $request): CarbonImmutable
    {
        $request->validate(['at' => ['nullable', 'date_format:Y-m-d H:i']]);

        $at = $request->filled('at')
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', $request->input('at'), $request->user()->agency->timezone)
            : CarbonImmutable::now();

        if ($at->gt(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['at' => 'This time cannot be in the future.']);
        }

        return $at;
    }
}
