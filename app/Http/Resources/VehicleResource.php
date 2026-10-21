<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
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
            'fleet_code' => $this->fleet_code,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'plate' => $this->plate,
            'vin' => $this->vin,
            'category' => $this->category,
            'fuel' => $this->fuel,
            'transmission' => $this->transmission,
            'seats' => $this->seats,
            'doors' => $this->doors,
            'color' => $this->color,
            'mileage' => $this->mileage,
            'first_registration' => $this->first_registration?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'pricing' => [
                'daily' => $this->daily_price,
                'weekly' => $this->weekly_price,
                'monthly' => $this->monthly_price,
                'deposit' => $this->deposit,
                'included_km_per_day' => $this->included_km_per_day,
                'extra_km_fee' => $this->extra_km_fee,
            ],
            'open_damages_count' => $this->whenHas('open_damages_count'),
            'archived_at' => $this->archived_at,
            'archive_reason' => $this->archive_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
