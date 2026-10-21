<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
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
            'customer_code' => $this->customer_code,
            'type' => $this->type,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'id_type' => $this->id_type,
            'id_number' => $this->id_number,
            'nationality' => $this->nationality,
            'birth_date' => $this->birth_date->toDateString(),
            'licence_number' => $this->licence_number,
            'licence_issue_date' => $this->licence_issue_date?->toDateString(),
            'notes' => $this->notes,
            'is_blocked' => $this->isBlocked(),
            'blocked_at' => $this->blocked_at,
            'block_reason' => $this->block_reason,
            'archived_at' => $this->archived_at,
            'archive_reason' => $this->archive_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
