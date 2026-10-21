<?php

namespace App\Http\Controllers\Api;

use App\Enums\DamageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RepairDamageRequest;
use App\Http\Resources\VehicleDamageResource;
use App\Models\VehicleDamage;
use Illuminate\Support\Facades\Gate;

/**
 * Repaired damage leaves the drawing but stays in the car's history.
 */
class VehicleDamageRepairController extends Controller
{
    /**
     * POST /api/vehicle-damages/{damage}/repair  { repaired_at, repair_cost, repair_note }
     */
    public function store(RepairDamageRequest $request, VehicleDamage $damage): VehicleDamageResource
    {
        abort_unless($damage->isOpen(), 409, 'This damage is already repaired.');

        $damage->forceFill([
            'status' => DamageStatus::Repaired,
            ...$request->validated(),
        ])->save();

        return new VehicleDamageResource($damage);
    }

    /**
     * DELETE /api/vehicle-damages/{damage}/repair — owner only: marked repaired by mistake.
     */
    public function destroy(VehicleDamage $damage): VehicleDamageResource
    {
        Gate::authorize('reopen', $damage);

        abort_if($damage->isOpen(), 409, 'This damage is not repaired.');

        $damage->forceFill([
            'status' => DamageStatus::Open,
            'repaired_at' => null,
            'repair_cost' => null,
            'repair_note' => null,
        ])->save();

        return new VehicleDamageResource($damage);
    }
}
