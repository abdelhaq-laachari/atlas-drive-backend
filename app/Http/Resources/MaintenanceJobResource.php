<?php

namespace App\Http\Resources;

use App\Models\MaintenanceJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MaintenanceJob
 */
class MaintenanceJobResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $request->user()->agency->timezone;
        $local = fn ($date) => $date?->setTimezone($timezone)->toIso8601String();

        return [
            'id' => $this->id,
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle->id,
                'fleet_code' => $this->vehicle->fleet_code,
                'make' => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'plate' => $this->vehicle->plate,
            ]),
            'type' => $this->type,
            'type_label' => $this->type->label(),
            'status' => $this->status,
            'scheduled_for' => $this->scheduled_for?->toDateString(),
            'started_at' => $local($this->started_at),
            'completed_at' => $local($this->completed_at),
            'garage' => $this->garage,
            'mileage' => $this->mileage,
            'cost' => $this->cost,
            'description' => $this->description,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
