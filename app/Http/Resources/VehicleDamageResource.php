<?php

namespace App\Http\Resources;

use App\Models\VehicleDamage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VehicleDamage
 */
class VehicleDamageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'x' => $this->x,
            'y' => $this->y,
            'area' => $this->area,
            'type' => $this->type,
            'size' => $this->size,
            'note' => $this->note,
            'found_at' => $this->found_at,
            'found_stage' => $this->found_stage,
            'rental_id' => $this->rental_id,
            'status' => $this->status,
            'repaired_at' => $this->repaired_at?->toDateString(),
            'repair_cost' => $this->repair_cost,
            'repair_note' => $this->repair_note,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
