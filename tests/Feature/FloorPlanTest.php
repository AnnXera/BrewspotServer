<?php

namespace Tests\Feature;

use App\Models\BranchTable;
use App\Models\Feature;

/**
 * Floor plans, layout save, table status, and the register's read-only view.
 */
class FloorPlanTest extends FloorPlanTestCase
{
    // ── Gate + access ────────────────────────────────────────────────────

    public function test_floor_plans_need_the_reservations_feature(): void
    {
        $token = $this->tokenFor($this->owner);

        $this->api('GET', $this->base('owner') . '/floor-plans', $token)->assertOk();

        $this->subscriptionPlan->features()->detach(Feature::where('key', 'reservations')->value('feature_id'));

        $this->api('GET', $this->base('owner') . '/floor-plans', $token)
            ->assertForbidden()
            ->assertJsonPath('required_feature', 'reservations');
    }

    public function test_manager_only_reaches_plans_of_their_own_branch(): void
    {
        $otherPlan = $this->makePlan($this->otherBranch, 'Other Floor');
        $token     = $this->tokenFor($this->manager);

        $this->api('GET', $this->base('manager', $this->otherBranch) . '/floor-plans', $token)->assertForbidden();

        // A plan uuid from another branch reads as not found through my branch.
        $this->api('GET', $this->base('manager') . "/floor-plans/{$otherPlan->uuid}", $token)->assertNotFound();
        $this->api('PUT', $this->base('manager') . "/floor-plans/{$otherPlan->uuid}/layout", $token, ['tables' => [], 'elements' => []])->assertNotFound();
        $this->api('DELETE', $this->base('manager') . "/floor-plans/{$otherPlan->uuid}", $token)->assertNotFound();
    }

    public function test_assets_endpoint_lists_the_catalog(): void
    {
        $this->api('GET', $this->base('manager') . '/floor-plans/assets', $this->tokenFor($this->manager))
            ->assertOk()
            ->assertJsonPath('table_statuses', ['available', 'occupied', 'reserved', 'cleaning'])
            ->assertJsonPath('reservation_rules.default_duration_minutes', 90)
            ->assertJsonFragment(['key' => 'table_round_4', 'category' => 'table', 'capacity' => 4]);
    }

    public function test_every_catalog_asset_has_a_category_and_tables_have_a_capacity(): void
    {
        foreach (config('floor_plan.assets') as $key => $meta) {
            $this->assertNotEmpty($meta['category'] ?? null, "$key has no category");

            if ($meta['category'] === 'table') {
                $this->assertGreaterThan(0, $meta['capacity'] ?? 0, "$key has no capacity");
            }
        }
    }

    // ── Plans ────────────────────────────────────────────────────────────

    public function test_first_plan_is_active_and_activating_another_deactivates_the_rest(): void
    {
        $token = $this->tokenFor($this->manager);
        $url   = $this->base('manager') . '/floor-plans';

        $first = $this->api('POST', $url, $token, ['floorplan_name' => 'Indoor', 'canvas_width' => 1000, 'canvas_height' => 800])
            ->assertCreated()->assertJsonPath('floor_plan.is_active', true)->json('floor_plan.uuid');

        $second = $this->api('POST', $url, $token, ['floorplan_name' => 'Patio', 'canvas_width' => 600, 'canvas_height' => 400])
            ->assertCreated()->assertJsonPath('floor_plan.is_active', false)->json('floor_plan.uuid');

        $this->api('POST', "$url/$second/activate", $token)->assertOk()->assertJsonPath('floor_plan.is_active', true);

        $this->api('GET', $url, $token)->assertOk()
            ->assertJsonPath('floor_plans.0.uuid', $second)
            ->assertJsonPath('floor_plans.0.is_active', true)
            ->assertJsonPath('floor_plans.1.uuid', $first)
            ->assertJsonPath('floor_plans.1.is_active', false);
    }

    public function test_plan_names_are_unique_per_branch_ignoring_case(): void
    {
        $token = $this->tokenFor($this->owner);
        $url   = $this->base('owner') . '/floor-plans';

        $this->api('POST', $url, $token, ['floorplan_name' => 'Indoor', 'canvas_width' => 1000, 'canvas_height' => 800])->assertCreated();
        $this->api('POST', $url, $token, ['floorplan_name' => 'indoor', 'canvas_width' => 1000, 'canvas_height' => 800])
            ->assertStatus(422)->assertJsonValidationErrors('floorplan_name');

        // Another branch can reuse the name.
        $this->api('POST', $this->base('owner', $this->otherBranch) . '/floor-plans', $token, ['floorplan_name' => 'Indoor', 'canvas_width' => 1000, 'canvas_height' => 800])
            ->assertCreated();
    }

    public function test_plan_validation(): void
    {
        $this->api('POST', $this->base('owner') . '/floor-plans', $this->tokenFor($this->owner), [
            'floorplan_name'  => 'Bad',
            'canvas_width'    => 5,
            'canvas_height'   => 800,
            'boundary_points' => [['x' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['canvas_width', 'boundary_points.0.y']);
    }

    public function test_canvas_cannot_shrink_below_existing_items(): void
    {
        $plan = $this->makePlan($this->mainBranch);
        $this->makeTable($plan, 'T1')->update(['x_location' => 900]);

        $token = $this->tokenFor($this->manager);
        $url   = $this->base('manager') . "/floor-plans/{$plan->uuid}";

        $this->api('PATCH', $url, $token, ['canvas_width' => 500])->assertStatus(422)->assertJsonValidationErrors('canvas_width');
        $this->api('PATCH', $url, $token, ['canvas_width' => 950, 'floorplan_name' => 'Renamed'])
            ->assertOk()->assertJsonPath('floor_plan.floorplan_name', 'Renamed');
    }

    public function test_plan_with_upcoming_reservations_cannot_be_deleted(): void
    {
        $plan  = $this->makePlan($this->mainBranch);
        $table = $this->makeTable($plan);
        $res   = $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00');

        $token = $this->tokenFor($this->manager);
        $url   = $this->base('manager') . "/floor-plans/{$plan->uuid}";

        $this->api('DELETE', $url, $token)->assertStatus(409);

        $res->update(['status' => 'cancelled']);
        $this->api('DELETE', $url, $token)->assertOk();
        $this->assertSoftDeleted('floor_plans', ['floor_plan_id' => $plan->floor_plan_id]);
    }

    // ── Layout save ──────────────────────────────────────────────────────

    public function test_layout_save_creates_updates_and_removes(): void
    {
        $plan   = $this->makePlan($this->mainBranch);
        $keep   = $this->makeTable($plan, 'T1');
        $remove = $this->makeTable($plan, 'T2');
        $token  = $this->tokenFor($this->manager);
        $url    = $this->base('manager') . "/floor-plans/{$plan->uuid}/layout";

        $response = $this->api('PUT', $url, $token, [
            'tables' => [
                ['uuid' => $keep->uuid, 'table_name' => 'Window 1', 'capacity' => 6, 'asset_key' => 'table_rect_6', 'x_location' => 200, 'y_location' => 150, 'rotation' => 90],
                ['table_name' => 'Bar 1', 'capacity' => 2, 'asset_key' => 'table_bar_high', 'x_location' => 400, 'y_location' => 300],
            ],
            'elements' => [
                ['category' => 'plant', 'asset_key' => 'plant_small', 'x_location' => 10, 'y_location' => 10, 'z_index' => 2],
                ['category' => 'wall', 'label' => 'North', 'x_location' => 500, 'y_location' => 5, 'width' => 1000, 'height' => 10],
            ],
        ])->assertOk()->assertJsonCount(2, 'floor_plan.tables')->assertJsonCount(2, 'floor_plan.elements');

        $names = collect($response->json('floor_plan.tables'))->pluck('table_name')->all();
        $this->assertEqualsCanonicalizing(['Window 1', 'Bar 1'], $names);

        $this->assertSame(6, $keep->fresh()->capacity);
        $this->assertSoftDeleted('branch_tables', ['table_id' => $remove->table_id]);
        $this->assertSame('available', BranchTable::where('table_name', 'Bar 1')->value('status'));

        // Second save: elements are replaced wholesale, and an empty list removes them.
        $this->api('PUT', $url, $token, ['tables' => [], 'elements' => []])
            ->assertOk()->assertJsonCount(0, 'floor_plan.tables')->assertJsonCount(0, 'floor_plan.elements');
    }

    public function test_layout_save_does_not_overwrite_table_status(): void
    {
        $plan  = $this->makePlan($this->mainBranch);
        $table = $this->makeTable($plan, 'T1');
        $table->update(['status' => 'occupied']);

        $this->api('PUT', $this->base('manager') . "/floor-plans/{$plan->uuid}/layout", $this->tokenFor($this->manager), [
            'tables' => [['uuid' => $table->uuid, 'table_name' => 'T1', 'capacity' => 4, 'x_location' => 5, 'y_location' => 5, 'status' => 'available']],
            'elements' => [],
        ])->assertOk();

        $this->assertSame('occupied', $table->fresh()->status);
    }

    public function test_layout_validation(): void
    {
        $plan  = $this->makePlan($this->mainBranch);
        $token = $this->tokenFor($this->manager);
        $url   = $this->base('manager') . "/floor-plans/{$plan->uuid}/layout";

        $this->api('PUT', $url, $token, [
            'tables' => [
                ['table_name' => 'A', 'capacity' => 2, 'asset_key' => 'plant_small', 'x_location' => 1, 'y_location' => 1],   // not a table asset
                ['table_name' => 'a', 'capacity' => 0, 'x_location' => -5, 'y_location' => 1, 'rotation' => 400],             // duplicate name, bad numbers
            ],
            'elements' => [
                ['category' => 'plant', 'asset_key' => 'counter_pos', 'x_location' => 1, 'y_location' => 1],                    // category mismatch
                ['category' => 'plant', 'asset_key' => 'nope', 'x_location' => 1, 'y_location' => 1],                            // unknown asset
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'tables.0.asset_key', 'tables.1.capacity', 'tables.1.x_location', 'tables.1.rotation',
            'elements.1.asset_key',
        ]);

        // Rules that need the earlier errors out of the way.
        $this->api('PUT', $url, $token, [
            'tables' => [
                ['table_name' => 'A', 'capacity' => 2, 'x_location' => 1, 'y_location' => 1],
                ['table_name' => ' a ', 'capacity' => 2, 'x_location' => 2, 'y_location' => 2],
            ],
            'elements' => [['category' => 'plant', 'asset_key' => 'counter_pos', 'x_location' => 1, 'y_location' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['tables.1.table_name', 'elements.0.asset_key']);

        $this->api('PUT', $url, $token, ['elements' => []])->assertStatus(422)->assertJsonValidationErrors('tables');
    }

    public function test_walls_are_drawn_shapes_with_a_size_and_no_asset(): void
    {
        $plan  = $this->makePlan($this->mainBranch);
        $token = $this->tokenFor($this->manager);
        $url   = $this->base('manager') . "/floor-plans/{$plan->uuid}/layout";

        // A wall needs width and height, and carries no asset.
        $this->api('PUT', $url, $token, [
            'tables'   => [],
            'elements' => [
                ['category' => 'wall', 'x_location' => 100, 'y_location' => 100],
                ['category' => 'wall', 'asset_key' => 'plant_small', 'x_location' => 100, 'y_location' => 100, 'width' => 50, 'height' => 10],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['elements.0.width', 'elements.0.height', 'elements.1.asset_key']);

        $this->api('PUT', $url, $token, [
            'tables'   => [],
            'elements' => [['category' => 'wall', 'x_location' => 100, 'y_location' => 100, 'width' => 0, 'height' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('elements.0.width');

        // A valid wall is stored with its size and rotation; an asset element needs an asset.
        $this->api('PUT', $url, $token, [
            'tables'   => [],
            'elements' => [
                ['category' => 'wall', 'label' => 'Diagonal', 'x_location' => 300, 'y_location' => 200, 'width' => 400, 'height' => 12, 'rotation' => 45],
                ['category' => 'counter', 'asset_key' => 'counter_straight', 'x_location' => 50, 'y_location' => 50, 'width' => 220, 'height' => 60],
                ['category' => 'plant', 'asset_key' => 'plant_small', 'x_location' => 10, 'y_location' => 10],
            ],
        ])->assertOk()
            ->assertJsonFragment(['category' => 'wall', 'asset_key' => null, 'width' => 400.0, 'height' => 12.0, 'rotation' => 45.0])
            ->assertJsonFragment(['category' => 'counter', 'width' => 220.0, 'height' => 60.0])
            ->assertJsonFragment(['category' => 'plant', 'width' => null, 'height' => null]);

        $this->api('PUT', $url, $token, [
            'tables'   => [],
            'elements' => [['category' => 'plant', 'x_location' => 1, 'y_location' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('elements.0.asset_key');
    }

    public function test_assets_endpoint_reports_drawn_categories_and_has_no_wall_images(): void
    {
        $response = $this->api('GET', $this->base('manager') . '/floor-plans/assets', $this->tokenFor($this->manager))
            ->assertOk()->assertJsonPath('drawn_categories', ['wall']);

        $this->assertNotContains('wall', collect($response->json('assets'))->pluck('category')->all());
    }

    public function test_boundary_points_must_be_inside_the_canvas(): void
    {
        $token = $this->tokenFor($this->manager);
        $url   = $this->base('manager') . '/floor-plans';
        $room  = [['x' => 0, 'y' => 0], ['x' => 600, 'y' => 0], ['x' => 600, 'y' => 400], ['x' => 0, 'y' => 400]];

        $this->api('POST', $url, $token, ['floorplan_name' => 'Small', 'canvas_width' => 500, 'canvas_height' => 400, 'boundary_points' => $room])
            ->assertStatus(422)->assertJsonValidationErrors('boundary_points');

        $uuid = $this->api('POST', $url, $token, ['floorplan_name' => 'Room', 'canvas_width' => 800, 'canvas_height' => 400, 'boundary_points' => $room])
            ->assertCreated()->assertJsonCount(4, 'floor_plan.boundary_points')->json('floor_plan.uuid');

        // Shrinking the canvas below the saved boundary is refused too.
        $this->api('PATCH', "$url/$uuid", $token, ['canvas_width' => 500])->assertStatus(422)->assertJsonValidationErrors('boundary_points');
        $this->api('PATCH', "$url/$uuid", $token, ['boundary_points' => null])->assertOk()->assertJsonPath('floor_plan.boundary_points', null);
    }

    public function test_layout_items_must_fit_the_canvas(): void
    {
        $plan = $this->makePlan($this->mainBranch); // 1000 x 800

        $this->api('PUT', $this->base('manager') . "/floor-plans/{$plan->uuid}/layout", $this->tokenFor($this->manager), [
            'tables'   => [['table_name' => 'T1', 'capacity' => 4, 'x_location' => 1500, 'y_location' => 10]],
            'elements' => [['category' => 'plant', 'asset_key' => 'plant_small', 'x_location' => 10, 'y_location' => 900]],
        ])->assertStatus(422)->assertJsonValidationErrors(['tables.0.x_location', 'elements.0.y_location']);

        $this->assertSame(0, $plan->tables()->count());
    }

    public function test_layout_rejects_uuids_from_another_plan(): void
    {
        $plan      = $this->makePlan($this->mainBranch);
        $otherPlan = $this->makePlan($this->mainBranch, 'Patio', false);
        $foreign   = $this->makeTable($otherPlan, 'P1');

        $this->api('PUT', $this->base('manager') . "/floor-plans/{$plan->uuid}/layout", $this->tokenFor($this->manager), [
            'tables'   => [['uuid' => $foreign->uuid, 'table_name' => 'Stolen', 'capacity' => 4, 'x_location' => 1, 'y_location' => 1]],
            'elements' => [],
        ])->assertStatus(422);

        $this->assertSame('P1', $foreign->fresh()->table_name);
    }

    public function test_table_with_upcoming_reservation_cannot_be_removed_and_nothing_changes(): void
    {
        $plan  = $this->makePlan($this->mainBranch);
        $table = $this->makeTable($plan, 'T1');
        $this->makeReservation($table, '2026-10-06 10:00:00', '2026-10-06 11:00:00');

        $this->api('PUT', $this->base('manager') . "/floor-plans/{$plan->uuid}/layout", $this->tokenFor($this->manager), [
            // T1 is left out (removal) while a new table is added: the whole save must roll back.
            'tables'   => [['table_name' => 'New', 'capacity' => 2, 'x_location' => 5, 'y_location' => 5]],
            'elements' => [],
        ])->assertStatus(409);

        $this->assertSame(['T1'], $plan->tables()->pluck('table_name')->all());
    }

    public function test_removed_tables_keep_their_reservation_history(): void
    {
        $plan  = $this->makePlan($this->mainBranch);
        $table = $this->makeTable($plan, 'T1');
        $this->makeReservation($table, '2026-10-01 10:00:00', '2026-10-01 11:00:00', 'completed'); // in the past

        $token = $this->tokenFor($this->manager);
        $this->api('PUT', $this->base('manager') . "/floor-plans/{$plan->uuid}/layout", $token, ['tables' => [], 'elements' => []])->assertOk();

        $this->api('GET', $this->base('manager') . '/reservations?date=2026-10-01', $token)
            ->assertOk()
            ->assertJsonPath('reservations.data.0.table.table_name', 'T1')
            ->assertJsonPath('reservations.data.0.table.removed', true);
    }

    // ── Table status ─────────────────────────────────────────────────────

    public function test_table_status_can_be_changed_manually(): void
    {
        $plan  = $this->makePlan($this->mainBranch);
        $table = $this->makeTable($plan);
        $other = $this->makeTable($this->makePlan($this->otherBranch), 'X1');
        $token = $this->tokenFor($this->manager);

        $this->api('PATCH', $this->base('manager') . "/tables/{$table->uuid}/status", $token, ['status' => 'reserved'])
            ->assertOk()->assertJsonPath('table.status', 'reserved');

        $this->api('PATCH', $this->base('manager') . "/tables/{$table->uuid}/status", $token, ['status' => 'broken'])->assertStatus(422);
        $this->api('PATCH', $this->base('manager') . "/tables/{$other->uuid}/status", $token, ['status' => 'occupied'])->assertNotFound();
    }

    // ── Register (POS device) ────────────────────────────────────────────

    public function test_register_floor_plan_needs_an_unlocked_register(): void
    {
        $this->makeTable($this->makePlan($this->mainBranch));
        $device = $this->device(unlock: false);

        $this->api('GET', '/api/pos/device/floor-plan', $device)->assertStatus(423)->assertJsonPath('requires_unlock', true);
        $this->api('GET', '/api/pos/device/reservations', $device)->assertStatus(423);
    }

    public function test_register_sees_active_plan_with_upcoming_reservation_and_can_set_status(): void
    {
        $plan   = $this->makePlan($this->mainBranch, 'Indoor', true);
        $table  = $this->makeTable($plan, 'T1');
        $this->makeTable($this->makePlan($this->mainBranch, 'Patio', false), 'P1');
        $this->makeReservation($table, '2026-10-05 12:00:00', '2026-10-05 13:00:00');

        $device = $this->device();

        $this->api('GET', '/api/pos/device/floor-plan', $device)
            ->assertOk()
            ->assertJsonPath('floor_plan.floorplan_name', 'Indoor')
            ->assertJsonCount(1, 'floor_plan.tables')
            ->assertJsonPath('floor_plan.tables.0.upcoming_reservation.customer_name', 'Walk In');

        $this->api('PATCH', "/api/pos/device/tables/{$table->uuid}/status", $device, ['status' => 'cleaning'])
            ->assertOk()->assertJsonPath('table.status', 'cleaning');
    }

    public function test_register_cannot_set_status_on_another_branchs_table(): void
    {
        $foreign = $this->makeTable($this->makePlan($this->otherBranch), 'X1');

        $this->api('PATCH', "/api/pos/device/tables/{$foreign->uuid}/status", $this->device(), ['status' => 'occupied'])->assertNotFound();
    }

    public function test_register_floor_plan_is_null_without_an_active_plan(): void
    {
        $this->makePlan($this->mainBranch, 'Indoor', false);

        $this->api('GET', '/api/pos/device/floor-plan', $this->device())->assertOk()->assertJsonPath('floor_plan', null);
    }

    public function test_register_cannot_use_dashboard_routes_and_needs_the_feature(): void
    {
        $device = $this->device();

        $this->api('PUT', $this->base('manager') . '/floor-plans/x/layout', $device, ['tables' => [], 'elements' => []])
            ->assertStatus(403);

        $this->subscriptionPlan->features()->detach(Feature::where('key', 'reservations')->value('feature_id'));

        $this->api('GET', '/api/pos/device/floor-plan', $device)->assertForbidden()->assertJsonPath('required_feature', 'reservations');
    }
}
