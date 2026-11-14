<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\Feature;
use App\Models\InventoryServing;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\User;

/**
 * The owner gets the manager's branch screens (servings, reservations, floor plan editing)
 * for every branch of their own cafe, through /api/owner/branches/{branch}/..., and is
 * refused on another owner's branches.
 */
class OwnerBranchWorkspaceTest extends FloorPlanTestCase
{
    private User $rival;
    private CafeBranch $rivalBranch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriptionPlan->features()->attach(
            Feature::create(['key' => 'menu_management', 'name' => 'menu_management', 'is_active' => true])->feature_id
        );

        $this->rival       = $this->makeUser('Cafe Owner');
        $rivalCafe         = Cafe::create(['user_id' => $this->rival->user_id, 'cafe_name' => 'Rival Cafe']);
        $this->rivalBranch = $this->makeBranch($rivalCafe, 'Rival Branch');
    }

    private function owner(): string
    {
        return $this->tokenFor($this->owner);
    }

    // ── Floor plans ──────────────────────────────────────────────────────

    public function test_owner_creates_edits_and_switches_floor_plans_on_any_own_branch(): void
    {
        foreach ([$this->mainBranch, $this->otherBranch] as $branch) {
            $base = $this->base('owner', $branch) . '/floor-plans';
            $token = $this->owner();

            $this->api('GET', "$base/assets", $token)->assertOk();

            $uuid = $this->api('POST', $base, $token, ['floorplan_name' => 'Indoor', 'canvas_width' => 1000, 'canvas_height' => 800])
                ->assertCreated()->json('floor_plan.uuid');
            $this->api('PATCH', "$base/$uuid", $token, ['floorplan_name' => 'Ground Floor'])
                ->assertOk()->assertJsonPath('floor_plan.floorplan_name', 'Ground Floor');
            $this->api('PUT', "$base/$uuid/layout", $token, [
                'tables'   => [['table_name' => 'T1', 'capacity' => 2, 'asset_key' => 'table_sqr_2', 'x_location' => 100, 'y_location' => 100]],
                'elements' => [],
            ])->assertOk();
            $this->api('POST', "$base/$uuid/deactivate", $token)->assertOk()->assertJsonPath('floor_plan.is_active', false);
            $this->api('POST', "$base/$uuid/activate", $token)->assertOk()->assertJsonPath('floor_plan.is_active', true);

            $this->api('GET', "$base/$uuid", $token)->assertOk()->assertJsonCount(1, 'floor_plan.tables');
            $this->assertSame(1, $branch->floorPlans()->count());
        }

        // The owner's changes are logged under their own name.
        $this->assertTrue(\App\Models\ActivityLog::where('category', 'floor_plans')->get()
            ->every(fn ($log) => $log->user_id === $this->owner->user_id && $log->actor_role === 'Cafe Owner'));
    }

    public function test_owner_cannot_reach_another_owners_floor_plans(): void
    {
        $rivalPlan = $this->makePlan($this->rivalBranch, 'Rival Floor');
        $rivalBase = $this->base('owner', $this->rivalBranch) . '/floor-plans';
        $token     = $this->owner();

        $this->api('GET', $rivalBase, $token)->assertForbidden();
        $this->api('POST', $rivalBase, $token, ['floorplan_name' => 'Mine now', 'canvas_width' => 1000, 'canvas_height' => 800])->assertForbidden();
        $this->api('PATCH', "$rivalBase/{$rivalPlan->uuid}", $token, ['floorplan_name' => 'Hacked'])->assertForbidden();
        $this->api('POST', "$rivalBase/{$rivalPlan->uuid}/deactivate", $token)->assertForbidden();

        // Nor through one of their own branches.
        $this->api('PATCH', $this->base('owner') . "/floor-plans/{$rivalPlan->uuid}", $token, ['floorplan_name' => 'Hacked'])->assertNotFound();

        $this->assertSame('Rival Floor', $rivalPlan->fresh()->floorplan_name);
        $this->assertTrue($rivalPlan->fresh()->is_active);

        // The manager route group stays closed to owners.
        $this->api('GET', $this->base('manager') . '/floor-plans', $token)->assertForbidden();
    }

    // ── Reservations ─────────────────────────────────────────────────────

    public function test_owner_lists_creates_updates_and_moves_reservations_on_an_own_branch(): void
    {
        $table = $this->makeTable($this->makePlan($this->otherBranch));
        $base  = $this->base('owner', $this->otherBranch) . '/reservations';
        $token = $this->owner();

        $this->api('GET', $this->base('owner', $this->otherBranch) . '/opening-hours', $token)->assertOk();

        $uuid = $this->api('POST', $base, $token, $this->booking($table))
            ->assertCreated()
            ->assertJsonPath('reservation.status', 'confirmed')
            ->json('reservation.uuid');

        $this->api('GET', $base, $token)->assertOk()->assertJsonPath('reservations.data.0.uuid', $uuid);
        $this->api('GET', "$base/$uuid", $token)->assertOk()->assertJsonPath('reservation.customer_name', 'Juan Dela Cruz');

        $this->api('PATCH', "$base/$uuid", $token, ['customer_name' => 'Maria Clara', 'party_size' => 3])
            ->assertOk()->assertJsonPath('reservation.customer_name', 'Maria Clara');

        $this->api('POST', "$base/$uuid/status", $token, ['status' => 'cancelled'])
            ->assertOk()->assertJsonPath('reservation.status', 'cancelled');
    }

    public function test_owner_cannot_reach_another_owners_reservations(): void
    {
        $rivalTable = $this->makeTable($this->makePlan($this->rivalBranch, 'Rival Floor'));
        $rivalRes   = $this->makeReservation($rivalTable, '2026-10-06 10:00:00', '2026-10-06 11:00:00');
        $rivalBase  = $this->base('owner', $this->rivalBranch) . '/reservations';
        $token      = $this->owner();

        $this->api('GET', $rivalBase, $token)->assertForbidden();
        $this->api('POST', $rivalBase, $token, $this->booking($rivalTable))->assertForbidden();
        $this->api('PATCH', "$rivalBase/{$rivalRes->uuid}", $token, ['customer_name' => 'Hacked'])->assertForbidden();
        $this->api('POST', "$rivalBase/{$rivalRes->uuid}/status", $token, ['status' => 'cancelled'])->assertForbidden();

        // Nor through one of their own branches.
        $ownBase = $this->base('owner') . '/reservations';
        $this->api('POST', "$ownBase/{$rivalRes->uuid}/status", $token, ['status' => 'cancelled'])->assertNotFound();
        $this->api('POST', $ownBase, $token, $this->booking($rivalTable))->assertNotFound();

        $this->assertSame('confirmed', $rivalRes->fresh()->status);
        $this->assertSame('Walk In', $rivalRes->fresh()->customer_name);
    }

    // ── Servings ─────────────────────────────────────────────────────────

    public function test_owner_manages_daily_servings_of_any_own_branch(): void
    {
        $category = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Drinks', 'is_available' => true]);
        $latte    = MenuItem::create([
            'cafe_id'         => $this->cafe->cafe_id,
            'men_category_id' => $category->men_category_id,
            'menu_name'       => 'Latte',
            'base_price'      => 120,
            'is_available'    => true,
        ]);
        $token = $this->owner();
        $base  = $this->base('owner', $this->otherBranch) . '/servings';

        $this->api('GET', "$base/categories", $token)->assertOk()->assertJsonPath('categories.data.0.name', 'Drinks');

        $this->api('PUT', "$base/categories/{$category->uuid}/items", $token, ['items' => [
            ['menu_item_uuid' => $latte->uuid, 'daily_limit' => 25, 'enabled' => true],
        ]])->assertOk()->assertJsonPath('message', 'Servings saved.');

        $this->api('GET', "$base/categories/{$category->uuid}/items", $token)
            ->assertOk()->assertJsonPath('items.data.0.daily_limit', 25);

        $this->assertDatabaseHas('inventory_servings', [
            'men_item_id'       => $latte->men_item_id,
            'branch_id'         => $this->otherBranch->branch_id,
            'expected_servings' => 25,
        ]);
        $this->assertSame(0, InventoryServing::where('branch_id', $this->mainBranch->branch_id)->count());
    }

    public function test_owner_cannot_reach_another_owners_servings(): void
    {
        $base  = $this->base('owner', $this->rivalBranch) . '/servings';
        $token = $this->owner();

        $this->api('GET', "$base/categories", $token)->assertForbidden();
        $this->api('GET', $base, $token)->assertForbidden();
        $this->api('PUT', "$base/categories/uncategorized/items", $token, ['items' => []])->assertForbidden();

        // And the other way round.
        $this->api('GET', $this->base('owner') . '/servings/categories', $this->tokenFor($this->rival))->assertForbidden();
    }
}
