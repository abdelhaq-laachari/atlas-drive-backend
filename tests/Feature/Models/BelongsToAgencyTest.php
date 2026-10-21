<?php

namespace Tests\Feature\Models;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantItem;
use Tests\TestCase;

class BelongsToAgencyTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        // A temporary table does not break the test transaction in MySQL.
        Schema::create('tenant_items', function (Blueprint $table) {
            $table->temporary();
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('name');
            $table->timestamps();
        });

        $this->userA = User::factory()->for(Agency::factory())->create();
        $this->userB = User::factory()->for(Agency::factory())->create();
    }

    private function itemFor(User $user, string $name): TenantItem
    {
        $this->actingAs($user);

        return TenantItem::create(['name' => $name]);
    }

    public function test_agency_id_is_filled_from_the_logged_in_user(): void
    {
        $item = $this->itemFor($this->userA, 'Dacia Logan');

        $this->assertSame($this->userA->agency_id, $item->agency_id);
        $this->assertTrue($item->agency->is($this->userA->agency));
    }

    public function test_a_user_only_sees_their_own_agency_rows(): void
    {
        $this->itemFor($this->userA, 'Car of A');
        $this->itemFor($this->userB, 'Car of B');

        $this->actingAs($this->userA);

        $this->assertSame(['Car of A'], TenantItem::pluck('name')->all());
    }

    public function test_another_agency_row_cannot_be_found_by_id(): void
    {
        $itemOfB = $this->itemFor($this->userB, 'Car of B');

        $this->actingAs($this->userA);

        $this->assertNull(TenantItem::find($itemOfB->id));
    }

    public function test_a_user_cannot_create_a_row_for_another_agency(): void
    {
        $this->actingAs($this->userA);

        $item = new TenantItem(['name' => 'Sneaky']);
        $item->agency_id = $this->userB->agency_id;
        $item->save();

        $this->assertSame($this->userA->agency_id, $item->agency_id);
    }

    public function test_bulk_updates_and_deletes_only_touch_the_own_agency(): void
    {
        $this->itemFor($this->userA, 'Car of A');
        $itemOfB = $this->itemFor($this->userB, 'Car of B');

        $this->actingAs($this->userA);
        TenantItem::query()->update(['name' => 'Renamed']);
        TenantItem::query()->delete();

        $this->assertDatabaseHas('tenant_items', ['id' => $itemOfB->id, 'name' => 'Car of B']);
    }

    public function test_without_login_no_filter_is_applied(): void
    {
        $this->itemFor($this->userA, 'Car of A');
        $this->itemFor($this->userB, 'Car of B');

        $this->app['auth']->forgetGuards();

        // Seeders, console commands and jobs see every agency and must filter themselves.
        $this->assertSame(2, TenantItem::count());
    }
}
