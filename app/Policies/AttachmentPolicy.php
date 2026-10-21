<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * Staff upload and open files (daily work); only the owner deletes one.
 * Agency isolation: BelongsToAgency (another agency's file is "not found").
 */
class AttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Attachment $attachment): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        return $user->isOwner();
    }
}
