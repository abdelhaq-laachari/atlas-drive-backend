<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 *
 * Never contains the password hash or the remember token.
 */
class StaffResource extends JsonResource
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
            'is_me' => $this->id === $request->user()->id,
            'is_active' => $this->isActive(),
            'deactivated_at' => $this->deactivated_at,
            'must_change_password' => $this->must_change_password,
            'two_factor_enabled' => $this->hasEnabledTwoFactorAuthentication(),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
