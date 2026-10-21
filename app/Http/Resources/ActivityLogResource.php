<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
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
            'action' => $this->action,
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $this->subject_label,
            ],
            // null user = done by the system (seeders, scheduled tasks)
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'changes' => $this->diff,
            'created_at' => $this->created_at,
        ];
    }
}
