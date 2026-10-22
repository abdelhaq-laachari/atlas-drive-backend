<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
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
            'email' => $this->email,
            'role' => $this->role,
            // true = the app must show "choose your own password" before anything else
            'must_change_password' => $this->must_change_password,
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
            'agency' => new AgencyResource($this->whenLoaded('agency')),
        ];
    }
}
