<?php

namespace App\Http\Resources;

use App\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agency
 */
class AgencyResource extends JsonResource
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
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'rules' => [
                'late_grace_minutes' => $this->late_grace_minutes,
                'fuel_price_per_quarter' => $this->fuel_price_per_quarter,
                'driver_min_age' => $this->driver_min_age,
                'licence_min_years' => $this->licence_min_years,
                'document_expiring_soon_days' => $this->document_expiring_soon_days,
                'suggest_return_prices' => $this->suggest_return_prices,
            ],
        ];
    }
}
