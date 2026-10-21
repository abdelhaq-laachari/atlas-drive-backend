<?php

namespace App\Http\Controllers\Api;

use App\Enums\DamageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleDamageRequest;
use App\Http\Resources\VehicleDamageResource;
use App\Models\Vehicle;
use App\Models\VehicleDamage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class VehicleDamageController extends Controller
{
    /**
     * GET /api/vehicles/{vehicle}/damages?status=open — oldest first, so the numbers on the drawing stay stable.
     */
    public function index(Request $request, Vehicle $vehicle): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', VehicleDamage::class);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(DamageStatus::class)],
        ]);

        $damages = $vehicle->damages()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('found_at')
            ->orderBy('id')
            ->get();

        return VehicleDamageResource::collection($damages);
    }

    /**
     * POST /api/vehicles/{vehicle}/damages — damage found outside a rental (e.g. inspection at the agency).
     * Damage found at hand-over / return will be recorded by the Rentals module.
     */
    public function store(VehicleDamageRequest $request, Vehicle $vehicle): VehicleDamageResource
    {
        abort_if($vehicle->isArchived(), 409, 'This car is archived.');

        $damage = new VehicleDamage($request->validated());
        $damage->found_at ??= now();
        $damage->vehicle()->associate($vehicle);
        $damage->save();

        return new VehicleDamageResource($damage->refresh());
    }

    /**
     * PUT /api/vehicle-damages/{damage} — correct an open damage.
     */
    public function update(VehicleDamageRequest $request, VehicleDamage $damage): VehicleDamageResource
    {
        abort_unless($damage->isOpen(), 409, 'This damage is repaired. Reopen it before editing.');

        $damage->fill($request->validated());
        $damage->found_at ??= $damage->getOriginal('found_at');
        $damage->save();

        return new VehicleDamageResource($damage);
    }

    /**
     * DELETE /api/vehicle-damages/{damage} — owner only (recorded by mistake).
     */
    public function destroy(VehicleDamage $damage): Response
    {
        Gate::authorize('delete', $damage);

        $damage->delete();

        return response()->noContent();
    }
}
