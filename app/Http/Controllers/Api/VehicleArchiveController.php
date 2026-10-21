<?php

namespace App\Http\Controllers\Api;

use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Archive a sold / retired car, or restore it. Cars are never deleted once they have history.
 */
class VehicleArchiveController extends Controller
{
    /**
     * POST /api/vehicles/{vehicle}/archive  { "reason": "Sold" }
     */
    public function store(Request $request, Vehicle $vehicle): VehicleResource
    {
        Gate::authorize('archive', $vehicle);

        abort_if($vehicle->isArchived(), 409, 'This car is already archived.');
        abort_unless(
            $vehicle->status->isManual(),
            409,
            'This car is '.$vehicle->status->value.'. Close its rental before archiving it.',
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $vehicle->archived_at = now();
        $vehicle->archive_reason = $validated['reason'];
        $vehicle->status = VehicleStatus::OutOfService;
        $vehicle->save();

        return new VehicleResource($vehicle);
    }

    /**
     * DELETE /api/vehicles/{vehicle}/archive — back in the fleet, as available.
     */
    public function destroy(Vehicle $vehicle): VehicleResource
    {
        Gate::authorize('archive', $vehicle);

        abort_unless($vehicle->isArchived(), 409, 'This car is not archived.');

        $vehicle->archived_at = null;
        $vehicle->archive_reason = null;
        $vehicle->status = VehicleStatus::Available;
        $vehicle->save();

        return new VehicleResource($vehicle);
    }
}
