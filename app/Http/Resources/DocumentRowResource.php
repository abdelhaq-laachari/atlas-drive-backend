<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One document type of one car, from App\Support\DocumentOverview::rows():
 * { type, has_expiry, status, days_left, current, history, vehicle? }
 */
class DocumentRowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'vehicle' => $this->when(isset($row['vehicle']), fn () => [
                'id' => $row['vehicle']->id,
                'fleet_code' => $row['vehicle']->fleet_code,
                'make' => $row['vehicle']->make,
                'model' => $row['vehicle']->model,
                'plate' => $row['vehicle']->plate,
            ]),
            'type' => $row['type'],
            'has_expiry' => $row['type']->hasExpiry(),
            'status' => $row['status'],
            'days_left' => $row['days_left'],
            'current' => $row['current'] ? new VehicleDocumentResource($row['current']) : null,
            'history' => $this->when(isset($row['history']), fn () => VehicleDocumentResource::collection($row['history'])),
        ];
    }
}
