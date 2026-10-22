<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttachmentCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Support\ImageShrinker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Files attached to customers, rentals, cars, car documents and damage.
 */
class AttachmentController extends Controller
{
    /**
     * Records that can have files (morph aliases).
     */
    private const TYPES = ['customer', 'rental', 'vehicle', 'vehicle_document', 'vehicle_damage', 'maintenance_job'];

    public const MAX_KB = 10240; // 10 MB

    /**
     * GET /api/attachments?attachable_type=customer&attachable_id=5
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Attachment::class);

        $validated = $request->validate([
            'attachable_type' => ['required', Rule::in(self::TYPES)],
            'attachable_id' => ['required', 'integer'],
        ]);

        $owner = $this->findOwner($validated['attachable_type'], $validated['attachable_id']);
        abort_unless($owner, 404);

        $attachments = Attachment::query()
            ->with('uploader')
            ->whereMorphedTo('attachable', $owner)
            ->latest('id')
            ->get();

        return AttachmentResource::collection($attachments);
    }

    /**
     * POST /api/attachments (multipart form): attachable_type, attachable_id, category, file
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Attachment::class);

        $validated = $request->validate([
            'attachable_type' => ['required', Rule::in(self::TYPES)],
            'attachable_id' => ['required', 'integer'],
            'category' => ['required', Rule::enum(AttachmentCategory::class)->only(
                AttachmentCategory::allowedFor((string) $request->input('attachable_type'))
            )],
            // Type checked from the file's content, not its name
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimetypes:image/jpeg,image/png,image/webp,application/pdf'],
        ], [
            'file.mimetypes' => 'Only photos (JPG, PNG, WEBP) and PDF files can be uploaded.',
            'file.max' => 'The file is too big (maximum 10 MB).',
            'category.enum' => 'This kind of file does not belong here.',
        ]);

        $owner = $this->findOwner($validated['attachable_type'], $validated['attachable_id']);
        if (! $owner) {
            throw ValidationException::withMessages(['attachable_id' => 'Not found.']);
        }

        $file = $request->file('file');
        [$contents, $mime, $extension] = $this->prepare($file->getRealPath(), $file->getMimeType());

        $attachment = new Attachment;
        $attachment->agency_id = $owner->agency_id;
        $attachment->attachable()->associate($owner);
        $attachment->category = AttachmentCategory::from($validated['category']);
        $attachment->original_name = mb_substr(basename($file->getClientOriginalName()), 0, 255);
        $attachment->mime = $mime;
        $attachment->uploaded_by = $request->user()->id;
        $attachment->storeContents($contents, $extension);

        try {
            $attachment->save();
        } catch (Throwable $e) {
            $attachment->deleteFile(); // no file without its row
            throw $e;
        }

        return (new AttachmentResource($attachment->load('uploader')))->response()->setStatusCode(201);
    }

    /**
     * GET /api/attachments/{attachment}/file[?download=1] — decrypted, only for this agency.
     */
    public function file(Request $request, Attachment $attachment): Response
    {
        Gate::authorize('view', $attachment);

        if ($attachment->category->isIdDocument()) {
            $attachment->recordView();
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $name = str_replace(['"', '\\'], '', $attachment->original_name);

        return response($attachment->contents(), 200, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => $disposition.'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * DELETE /api/attachments/{attachment} — owner only.
     */
    public function destroy(Attachment $attachment): Response
    {
        Gate::authorize('delete', $attachment);

        $attachment->delete();

        return response()->noContent();
    }

    /**
     * The record the file belongs to, only if it is in the user's agency (agency guard).
     */
    private function findOwner(string $type, int $id): ?Model
    {
        $class = Relation::getMorphedModel($type);

        return $class::find($id);
    }

    /**
     * Photos are shrunk and re-drawn as JPEG; PDFs are kept as they are.
     *
     * @return array{string, string, string} contents, mime, extension
     */
    private function prepare(string $path, ?string $mime): array
    {
        if ($mime === 'application/pdf') {
            $contents = (string) file_get_contents($path);
            if (! str_starts_with($contents, '%PDF-')) {
                throw ValidationException::withMessages(['file' => 'This PDF file is damaged.']);
            }

            return [$contents, 'application/pdf', 'pdf'];
        }

        try {
            return [ImageShrinker::toJpeg($path), 'image/jpeg', 'jpg'];
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['file' => 'This image could not be read.']);
        }
    }
}
