<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
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
            'attachable' => ['type' => $this->attachable_type, 'id' => $this->attachable_id],
            'category' => $this->category,
            'category_label' => $this->category->label(),
            'is_id_document' => $this->category->isIdDocument(),
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            // Open with the logged-in session (the API checks the agency); never a public link.
            'file_url' => url("/api/attachments/{$this->id}/file"),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader
                ? ['id' => $this->uploader->id, 'name' => $this->uploader->name]
                : null),
            'created_at' => $this->created_at,
        ];
    }
}
