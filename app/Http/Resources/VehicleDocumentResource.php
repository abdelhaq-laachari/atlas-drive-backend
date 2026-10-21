<?php

namespace App\Http\Resources;

use App\Models\VehicleDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VehicleDocument
 */
class VehicleDocumentResource extends JsonResource
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
            'type' => $this->type,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'issue_date' => $this->issue_date->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'cost' => $this->cost,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
