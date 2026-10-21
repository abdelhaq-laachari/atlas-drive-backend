<?php

namespace App\Http\Controllers\Api;

use App\Enums\DamageStatus;
use App\Enums\VehicleCategory;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VehicleController extends Controller
{
    /**
     * GET /api/vehicles?status=&category=&search=&archived=1&per_page=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Vehicle::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VehicleStatus::class)],
            'category' => ['nullable', Rule::enum(VehicleCategory::class)],
            'search' => ['nullable', 'string', 'max:50'],
            'archived' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $vehicles = Vehicle::query()
            ->withCount(['damages as open_damages_count' => fn ($query) => $query->where('status', DamageStatus::Open)])
            ->when(
                $request->boolean('archived'),
                fn ($query) => $query->archived(),
                fn ($query) => $query->inFleet(),
            )
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->where('category', $category))
            ->when($filters['search'] ?? null, fn ($query, $search) => $this->search($query, $search))
            ->orderBy('fleet_number')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return VehicleResource::collection($vehicles);
    }

    /**
     * POST /api/vehicles
     */
    public function store(VehicleRequest $request): VehicleResource
    {
        $vehicle = Vehicle::create($request->validated());

        return new VehicleResource($vehicle->refresh());
    }

    /**
     * GET /api/vehicles/{vehicle}
     */
    public function show(Vehicle $vehicle): VehicleResource
    {
        Gate::authorize('view', $vehicle);

        $vehicle->loadCount(['damages as open_damages_count' => fn ($query) => $query->where('status', DamageStatus::Open)]);

        return new VehicleResource($vehicle);
    }

    /**
     * PUT /api/vehicles/{vehicle}
     */
    public function update(VehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        abort_if($vehicle->isArchived(), 409, 'This car is archived. Restore it before editing.');

        $vehicle->update($request->validated());

        return new VehicleResource($vehicle);
    }

    /**
     * Search by fleet code (V-007 or 7), plate, make or model.
     */
    private function search($query, string $search): void
    {
        $query->where(function ($query) use ($search) {
            $term = '%'.$search.'%';

            $query->where('make', 'like', $term)
                ->orWhere('model', 'like', $term)
                ->orWhereRaw("CONCAT(make, ' ', model) like ?", [$term]);

            if ($plateKey = Vehicle::plateKey($search)) {
                $query->orWhere('plate_key', 'like', '%'.$plateKey.'%');
            }

            if ($number = Vehicle::numberFromCode($search, 'V-')) {
                $query->orWhere('fleet_number', $number);
            }
        });
    }
}
