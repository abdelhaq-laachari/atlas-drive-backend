<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * A file attached to a record. Stored encrypted on the private disk under a random name.
 */
class Attachment extends Model
{
    use BelongsToAgency, LogsActivity;

    public const DISK = 'local';

    /**
     * Footprints: the storage path is internal.
     *
     * @var list<string>
     */
    protected array $activityIgnored = ['path', 'attachable_type', 'attachable_id', 'uploaded_by'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AttachmentCategory::class,
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The row and the file go together.
        static::deleted(fn (Attachment $attachment) => $attachment->deleteFile());
    }

    /**
     * Encrypt and save the file contents under a random name in the agency's folder.
     */
    public function storeContents(string $contents, string $extension): void
    {
        $this->path = "agency-{$this->agency_id}/{$this->attachable_type}/".bin2hex(random_bytes(16)).".{$extension}.enc";
        $this->size = strlen($contents);

        Storage::disk(self::DISK)->put($this->path, Crypt::encryptString($contents));
    }

    public function deleteFile(): void
    {
        Storage::disk(self::DISK)->delete($this->path);
    }

    /**
     * The original (decrypted) file contents.
     */
    public function contents(): string
    {
        return Crypt::decryptString(Storage::disk(self::DISK)->get($this->path));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Footprint when someone opens an ID document (who looked at this passport?).
     */
    public function recordView(): void
    {
        $this->logActivity('viewed', null);
    }

    public function activityLabel(): string
    {
        $owner = $this->attachable;
        $ownerLabel = $owner && method_exists($owner, 'activityLabel') ? $owner->activityLabel() : $this->attachable_type;

        return $ownerLabel.' - '.$this->category->label();
    }
}
