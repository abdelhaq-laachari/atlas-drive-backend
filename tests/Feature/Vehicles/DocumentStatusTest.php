<?php

namespace Tests\Feature\Vehicles;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\DocumentOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentStatusTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        // Midday in Casablanca, so "today" is the same date in UTC and in the agency.
        $this->travelTo(now()->parse('2026-10-21 12:00:00', 'Africa/Casablanca'));
        $this->agency = Agency::factory()->create(); // "expiring soon" = 30 days
    }

    private function documentExpiringOn(string $date): VehicleDocument
    {
        return new VehicleDocument(['type' => 'insurance', 'issue_date' => '2025-11-01', 'expiry_date' => $date]);
    }

    /**
     * @return array<string, array{string, DocumentStatus, int}>
     */
    public static function expiryDates(): array
    {
        return [
            'expired yesterday' => ['2026-10-20', DocumentStatus::Expired, -1],
            'expires today (still valid today)' => ['2026-10-21', DocumentStatus::Expiring, 0],
            'expires in 30 days' => ['2026-11-20', DocumentStatus::Expiring, 30],
            'expires in 31 days' => ['2026-11-21', DocumentStatus::Valid, 31],
        ];
    }

    #[DataProvider('expiryDates')]
    public function test_status_and_days_left_from_the_expiry_date(string $expiry, DocumentStatus $status, int $daysLeft): void
    {
        $overview = new DocumentOverview($this->agency);
        $document = $this->documentExpiringOn($expiry);

        $this->assertSame($status, $overview->status($document));
        $this->assertSame($daysLeft, $overview->daysLeft($document));
    }

    public function test_no_document_is_missing_and_no_expiry_is_valid(): void
    {
        $overview = new DocumentOverview($this->agency);
        $registration = new VehicleDocument(['type' => 'registration', 'issue_date' => '2023-03-15']);

        $this->assertSame(DocumentStatus::Missing, $overview->status(null));
        $this->assertSame(DocumentStatus::Valid, $overview->status($registration));
        $this->assertNull($overview->daysLeft($registration));
    }

    public function test_the_expiring_soon_days_come_from_the_agency_settings(): void
    {
        $this->agency->document_expiring_soon_days = 7;
        $document = $this->documentExpiringOn('2026-11-01'); // in 11 days

        $this->assertSame(DocumentStatus::Valid, (new DocumentOverview($this->agency))->status($document));
    }

    public function test_today_is_the_agency_day_not_the_server_day(): void
    {
        // 00:30 in Casablanca on Oct 22 is still Oct 21 in UTC.
        $this->travelTo(now()->parse('2026-10-22 00:30:00', 'Africa/Casablanca'));

        $status = (new DocumentOverview($this->agency))->status($this->documentExpiringOn('2026-10-21'));

        $this->assertSame(DocumentStatus::Expired, $status);
    }

    public function test_the_newest_document_of_each_type_is_the_current_one(): void
    {
        $vehicle = Vehicle::factory()->for($this->agency)->create();
        $old = VehicleDocument::factory()->for($vehicle)->create(['issue_date' => '2024-10-01', 'expiry_date' => '2025-09-30']);
        $new = VehicleDocument::factory()->for($vehicle)->create(['issue_date' => '2025-10-01', 'expiry_date' => '2026-09-30']);

        $rows = (new DocumentOverview($this->agency))->rows($vehicle->load('documents'));
        $insurance = collect($rows)->firstWhere('type', DocumentType::Insurance);

        $this->assertTrue($insurance['current']->is($new));
        $this->assertTrue($insurance['history']->sole()->is($old));
        $this->assertSame(DocumentStatus::Expired, $insurance['status']); // the renewal also expired
        $this->assertCount(4, $rows); // one row per document type
        $this->assertSame(DocumentStatus::Missing, collect($rows)->firstWhere('type', DocumentType::Vignette)['status']);
    }
}
