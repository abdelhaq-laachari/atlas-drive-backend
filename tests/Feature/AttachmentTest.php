<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Rental;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Attachment::DISK);

        $this->owner = User::factory()->owner()->create();
        $this->staff = User::factory()->for($this->owner->agency)->create(['name' => 'Youssef']);
        $this->customer = Customer::factory()->for($this->owner->agency)->create(['first_name' => 'Amine', 'last_name' => 'Kettani']);
    }

    private function upload(array $data, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff)->post('/api/attachments', $data, ['Accept' => 'application/json']);
    }

    private function cinPhoto(int $width = 4000, int $height = 3000): UploadedFile
    {
        return UploadedFile::fake()->image('CIN Amine.jpg', $width, $height);
    }

    private function pdf(string $name = 'contract.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");
    }

    private function uploadCin(): Attachment
    {
        $this->upload([
            'attachable_type' => 'customer',
            'attachable_id' => $this->customer->id,
            'category' => 'id_front',
            'file' => $this->cinPhoto(),
        ])->assertCreated();

        return Attachment::latest('id')->first();
    }

    // ---- Uploading ----

    public function test_staff_can_upload_a_customer_id_card_photo(): void
    {
        $this->upload([
            'attachable_type' => 'customer',
            'attachable_id' => $this->customer->id,
            'category' => 'id_front',
            'file' => $this->cinPhoto(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.category', 'id_front')
            ->assertJsonPath('data.category_label', 'ID card (front)')
            ->assertJsonPath('data.is_id_document', true)
            ->assertJsonPath('data.original_name', 'CIN Amine.jpg')
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.uploaded_by.name', 'Youssef');
    }

    public function test_photos_are_shrunk_and_stored_encrypted_under_a_random_name(): void
    {
        $attachment = $this->uploadCin();

        $stored = Storage::disk(Attachment::DISK)->get($attachment->path);

        // On disk: encrypted, not a JPEG anybody could open
        $this->assertStringNotContainsString('JFIF', $stored);
        $this->assertStringNotContainsString('Amine', $attachment->path);
        $this->assertStringStartsWith("agency-{$this->owner->agency_id}/customer/", $attachment->path);

        // Decrypted: a JPEG, longest side 1600 px instead of 4000
        [$width, $height] = getimagesizefromstring($attachment->contents());
        $this->assertSame([1600, 1200], [$width, $height]);
        $this->assertSame(strlen($attachment->contents()), $attachment->size);
    }

    public function test_a_signed_contract_pdf_is_kept_as_it_is(): void
    {
        $rental = Rental::factory()->for($this->owner->agency)->create();
        $pdf = $this->pdf();
        $original = file_get_contents($pdf->getRealPath());

        $this->upload([
            'attachable_type' => 'rental',
            'attachable_id' => $rental->id,
            'category' => 'signed_contract',
            'file' => $pdf,
        ])->assertCreated()->assertJsonPath('data.mime', 'application/pdf');

        $this->assertSame($original, Attachment::sole()->contents());
    }

    public function test_a_car_document_scan_can_be_uploaded(): void
    {
        $document = VehicleDocument::factory()->create(['agency_id' => $this->owner->agency_id,
            'vehicle_id' => Vehicle::factory()->for($this->owner->agency)->create()->id]);

        $this->upload([
            'attachable_type' => 'vehicle_document',
            'attachable_id' => $document->id,
            'category' => 'scan',
            'file' => $this->pdf('insurance.pdf'),
        ])->assertCreated();
    }

    public function test_only_photos_and_pdfs_are_accepted_checked_by_content(): void
    {
        $base = ['attachable_type' => 'customer', 'attachable_id' => $this->customer->id, 'category' => 'id_front'];

        // A PHP script renamed to .jpg is refused: its content is not an image.
        // (A real file on disk: Laravel's fake files report their type from the name only.)
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, '<?php system($_GET["c"]); ?>');
        $disguised = new UploadedFile($path, 'photo.jpg', null, null, true);

        $this->upload($base + ['file' => $disguised])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Only photos (JPG, PNG, WEBP) and PDF files can be uploaded.']);

        // Second layer: even if a file looked like an image, it must be readable as one
        $this->upload($base + ['file' => UploadedFile::fake()->createWithContent('photo.jpg', 'not really a picture')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'This image could not be read.']);

        $this->upload($base + ['file' => UploadedFile::fake()->create('notes.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame([], Storage::disk(Attachment::DISK)->allFiles());
    }

    public function test_files_over_10_mb_are_refused(): void
    {
        $this->upload([
            'attachable_type' => 'customer',
            'attachable_id' => $this->customer->id,
            'category' => 'passport',
            'file' => UploadedFile::fake()->create('passport.pdf', 11000, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['file' => 'The file is too big (maximum 10 MB).']);
    }

    public function test_the_category_must_fit_the_record(): void
    {
        $this->upload([
            'attachable_type' => 'customer',
            'attachable_id' => $this->customer->id,
            'category' => 'signed_contract',
            'file' => $this->pdf(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['category' => 'This kind of file does not belong here.']);

        $this->upload([
            'attachable_type' => 'user',
            'attachable_id' => $this->staff->id,
            'category' => 'other',
            'file' => $this->pdf(),
        ])->assertUnprocessable()->assertJsonValidationErrors('attachable_type');
    }

    public function test_files_cannot_be_attached_to_another_agency_record(): void
    {
        $other = Customer::factory()->create();

        $this->upload([
            'attachable_type' => 'customer',
            'attachable_id' => $other->id,
            'category' => 'id_front',
            'file' => $this->cinPhoto(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['attachable_id' => 'Not found.']);

        $this->assertSame([], Storage::disk(Attachment::DISK)->allFiles());
    }

    public function test_a_visitor_who_is_not_logged_in_is_refused(): void
    {
        $attachment = $this->uploadCin();
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/attachments/{$attachment->id}/file")->assertUnauthorized();
        $this->postJson('/api/attachments', [])->assertUnauthorized();
    }

    // ---- Opening ----

    public function test_a_file_is_sent_decrypted_with_safe_headers(): void
    {
        $attachment = $this->uploadCin();

        $response = $this->get("/api/attachments/{$attachment->id}/file")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename="CIN Amine.jpg"');

        $this->assertSame($attachment->contents(), $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->get("/api/attachments/{$attachment->id}/file?download=1")
            ->assertHeader('Content-Disposition', 'attachment; filename="CIN Amine.jpg"');
    }

    public function test_opening_an_id_document_leaves_a_footprint(): void
    {
        $attachment = $this->uploadCin();

        $this->get("/api/attachments/{$attachment->id}/file")->assertOk();

        $log = ActivityLog::where('subject_type', 'attachment')->latest('id')->first();
        $this->assertSame('viewed', $log->action);
        $this->assertSame($this->staff->id, $log->user_id);
        $this->assertSame('C-001 Amine Kettani - ID card (front)', $log->subject_label);
    }

    public function test_another_agency_file_is_not_found(): void
    {
        $attachment = $this->uploadCin();
        $stranger = User::factory()->owner()->create();

        $this->actingAs($stranger)->get("/api/attachments/{$attachment->id}/file")->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/attachments/{$attachment->id}")->assertNotFound();
    }

    public function test_the_files_of_a_record_are_listed_newest_first(): void
    {
        $this->uploadCin();
        $this->upload([
            'attachable_type' => 'customer',
            'attachable_id' => $this->customer->id,
            'category' => 'licence_front',
            'file' => $this->cinPhoto(800, 600),
        ]);

        $this->getJson("/api/attachments?attachable_type=customer&attachable_id={$this->customer->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category', 'licence_front')
            ->assertJsonPath('data.0.file_url', url('/api/attachments/'.Attachment::max('id').'/file'));
    }

    // ---- Deleting ----

    public function test_only_the_owner_can_delete_a_file_and_the_file_is_removed_from_disk(): void
    {
        $attachment = $this->uploadCin();

        $this->actingAs($this->staff)->deleteJson("/api/attachments/{$attachment->id}")->assertForbidden();

        $this->actingAs($this->owner)->deleteJson("/api/attachments/{$attachment->id}")->assertNoContent();

        $this->assertModelMissing($attachment);
        Storage::disk(Attachment::DISK)->assertMissing($attachment->path);
        $this->assertSame('deleted', ActivityLog::where('subject_type', 'attachment')->latest('id')->first()->action);
    }
}
