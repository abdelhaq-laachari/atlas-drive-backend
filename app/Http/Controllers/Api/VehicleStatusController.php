<?php

namespace App\Http\Controllers\Api;

use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/vehicles/{vehicle}/status — staff mark a car available, in maintenance or out of service.
 * Rented and Reserved are only set by rentals.
 */
class VehicleStatusController extends Controller
{
    public function update(Request $request, Vehicle $vehicle): VehicleResource
    {
        Gate::authorize('changeStatus', $vehicle);

        abort_if($vehicle->isArchived(), 409, 'This car is archived. Restore it before changing its status.');
        abort_unless(
            $vehicle->status->isManual(),
            409,
            'This car is '.$vehicle->status->value.'. Its status follows the rental.',
        );

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($status) => $status->value, VehicleStatus::manual()))],
        ], [
            'status.in' => 'Only available, maintenance or out-of-service can be set by hand.',
        ]);

        $vehicle->status = VehicleStatus::from($validated['status']);
        $vehicle->save();

        return new VehicleResource($vehicle);
    }
}
