<?php

namespace Tests\Feature\Settings;

use App\Models\ActivityLog;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::factory()->create(['name' => 'Atlas Drive', 'phone' => null, 'email' => null, 'address' => null, 'city' => 'Casablanca']);
        $this->owner = User::factory()->owner()->for($agency)->create();
        $this->staff = User::factory()->for($agency)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Atlas Drive Location',
            'phone' => '+212 522-000000',
            'email' => 'contact@atlasdrive.ma',
            'address' => '12 Bd Zerktouni',
            'city' => 'Casablanca',
            'ice' => '001234567000089',
            'if_number' => '12345678',
            'rc_number' => '123456',
            'patente_number' => '34567890',
            'late_grace_minutes' => 30,
            'fuel_price_per_quarter' => 160,
            'driver_min_age' => 23,
            'licence_min_years' => 3,
            'document_expiring_soon_days' => 45,
            'suggest_return_prices' => false,
        ], $overrides);
    }

    public function test_everyone_in_the_agency_can_read_the_settings_with_the_checklist(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/settings/agency')
            ->assertOk()
            ->assertJsonPath('data.name', 'Atlas Drive')
            ->assertJsonPath('data.currency', 'MAD')
            ->assertJsonPath('data.rules.late_grace_minutes', 60)
            ->assertJsonPath('data.setup.complete', false)
            ->assertJsonPath('data.setup.missing', ['phone', 'email', 'address', 'ice', 'if_number', 'rc_number', 'patente_number']);
    }

    public function test_the_owner_completes_the_agency_and_the_checklist_is_done(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/settings/agency', $this->settingsForm())
            ->assertOk()
            ->assertJsonPath('data.name', 'Atlas Drive Location')
            ->assertJsonPath('data.legal.ice', '001234567000089')
            ->assertJsonPath('data.rules.late_grace_minutes', 30)
            ->assertJsonPath('data.rules.fuel_price_per_quarter', '160.00')
            ->assertJsonPath('data.rules.driver_min_age', 23)
            ->assertJsonPath('data.setup.complete', true)
            ->assertJsonPath('data.setup.missing', []);
    }

    public function test_the_new_rules_are_used_by_the_app_at_once(): void
    {
        $this->actingAs($this->owner)->putJson('/api/settings/agency', $this->settingsForm(['late_grace_minutes' => 15]));

        $this->getJson('/api/me')->assertJsonPath('data.agency.rules.late_grace_minutes', 15);
    }

    public function test_staff_cannot_change_the_settings(): void
    {
        $this->actingAs($this->staff)
            ->putJson('/api/settings/agency', $this->settingsForm())
            ->assertForbidden();
    }

    public function test_currency_and_timezone_cannot_be_changed_from_settings(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/settings/agency', $this->settingsForm(['currency' => 'EUR', 'timezone' => 'Europe/Paris']))
            ->assertOk()
            ->assertJsonPath('data.currency', 'MAD')
            ->assertJsonPath('data.timezone', 'Africa/Casablanca');
    }

    public function test_legal_numbers_and_rules_are_checked(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/settings/agency', $this->settingsForm([
                'ice' => '12345',
                'if_number' => 'AB12',
                'phone' => 'call me',
                'fuel_price_per_quarter' => 150.5,
                'driver_min_age' => 16,
                'late_grace_minutes' => -5,
                'document_expiring_soon_days' => 0,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'ice' => 'The ICE has exactly 15 digits.',
                'if_number' => 'The IF (identifiant fiscal) contains digits only.',
                'phone', 'fuel_price_per_quarter', 'driver_min_age', 'late_grace_minutes', 'document_expiring_soon_days',
            ]);
    }

    public function test_changes_leave_footprints(): void
    {
        $this->actingAs($this->owner)->putJson('/api/settings/agency', $this->settingsForm(['late_grace_minutes' => 30]));

        $log = ActivityLog::where('subject_type', 'agency')->sole();
        $this->assertSame('updated', $log->action);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('Agency settings', $log->subject_label);
        $this->assertEquals(['old' => 60, 'new' => 30], $log->diff['late_grace_minutes']); // MySQL reorders JSON keys

        $this->getJson('/api/activity')->assertJsonPath('data.0.subject.label', 'Agency settings');
    }

    public function test_each_owner_only_ever_touches_their_own_agency(): void
    {
        $otherOwner = User::factory()->owner()->create();

        $this->actingAs($otherOwner)->putJson('/api/settings/agency', $this->settingsForm(['name' => 'Other']))->assertOk();

        $this->assertSame('Atlas Drive', $this->owner->agency->fresh()->name);
    }

    public function test_a_visitor_who_is_not_logged_in_is_refused(): void
    {
        $this->getJson('/api/settings/agency')->assertUnauthorized();
    }
}
