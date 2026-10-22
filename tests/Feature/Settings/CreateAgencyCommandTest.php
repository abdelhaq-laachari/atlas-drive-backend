<?php

namespace Tests\Feature\Settings;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAgencyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_agency_and_its_owner_with_a_temporary_password(): void
    {
        $this->artisan('agency:create', [
            '--name' => 'Atlas Drive',
            '--city' => 'Casablanca',
            '--owner-name' => 'Karim Owner',
            '--owner-email' => 'karim@atlasdrive.ma',
        ])
            ->expectsOutputToContain('Agency "Atlas Drive" created')
            ->expectsOutputToContain('karim@atlasdrive.ma')
            ->assertSuccessful();

        $agency = Agency::sole();
        $this->assertSame('MAD', $agency->currency);
        $this->assertSame('Africa/Casablanca', $agency->timezone);
        $this->assertSame(60, $agency->late_grace_minutes); // default rules

        $owner = User::sole();
        $this->assertSame($agency->id, $owner->agency_id);
        $this->assertSame(Role::Owner, $owner->role);
        $this->assertTrue($owner->must_change_password);
        $this->assertFalse(Hash::check('password', $owner->password));
    }

    public function test_another_country_gets_its_currency_and_timezone_once(): void
    {
        $this->artisan('agency:create', [
            '--name' => 'Tunis Cars', '--city' => 'Tunis', '--owner-name' => 'Owner', '--owner-email' => 'o@tunis.tn',
            '--currency' => 'tnd', '--timezone' => 'Africa/Tunis',
        ])->assertSuccessful();

        $this->assertSame(['TND', 'Africa/Tunis'], [Agency::sole()->currency, Agency::sole()->timezone]);
    }

    public function test_bad_input_creates_nothing(): void
    {
        User::factory()->create(['email' => 'taken@atlasdrive.ma']);

        $this->artisan('agency:create', [
            '--name' => 'X', '--city' => 'Y', '--owner-name' => 'Z', '--owner-email' => 'taken@atlasdrive.ma', '--timezone' => 'Mars/Base',
        ])
            ->expectsOutputToContain('email has already been taken')
            ->assertFailed();

        $this->assertSame(1, Agency::count()); // only the existing user's agency
    }
}
