<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Feature;

/**
 * Menu changes in the activity log, and the manager's branch view of it.
 * Reuses the floor plan fixtures: an owner, two branches, a manager and a cashier at the main branch.
 */
class ActivityLogTest extends FloorPlanTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['menu_management', 'staff_management'] as $key) {
            $this->subscriptionPlan->features()->attach(
                Feature::create(['key' => $key, 'name' => $key, 'is_active' => true])->feature_id
            );
        }
    }

    public function test_owner_menu_changes_are_logged(): void
    {
        $owner = $this->tokenFor($this->owner);

        $category = $this->api('POST', '/api/owner/menu-categories', $owner, ['name' => 'Drinks', 'is_available' => true])
            ->assertCreated()->json('category.uuid');
        $item = $this->api('POST', '/api/owner/menu-items', $owner, [
            'menu_name'     => 'Latte',
            'base_price'    => 120,
            'category_uuid' => $category,
            'recipes'       => [['ingredient_name' => 'Milk', 'quantity' => 150, 'unit' => 'ml']],
        ])->assertSuccessful()->json('item.uuid');

        $this->api('PATCH', "/api/owner/menu-items/$item", $owner, ['menu_name' => 'Cafe Latte', 'base_price' => 130])->assertOk();
        $this->api('PATCH', "/api/owner/menu-items/$item/branches/{$this->mainBranch->uuid}", $owner, ['is_available' => false])->assertOk();
        $this->api('PATCH', "/api/owner/menu-categories/$category/branches/{$this->otherBranch->uuid}", $owner, ['is_available' => false])->assertOk();
        $this->api('PATCH', "/api/owner/menu-categories/$category", $owner, ['name' => 'Coffee'])->assertOk();
        $this->api('DELETE', "/api/owner/menu-items/$item", $owner)->assertOk();
        $this->api('DELETE', "/api/owner/menu-categories/$category", $owner)->assertOk();

        $logs = ActivityLog::where('category', ActivityLog::CATEGORY_MENU)->orderBy('activity_log_id')->get();
        $this->assertSame([
            ActivityLog::MENU_CATEGORY_CREATED, ActivityLog::MENU_ITEM_CREATED, ActivityLog::MENU_ITEM_UPDATED,
            ActivityLog::MENU_ITEM_AVAILABILITY, ActivityLog::MENU_CATEGORY_AVAILABILITY, ActivityLog::MENU_CATEGORY_UPDATED,
            ActivityLog::MENU_ITEM_DELETED, ActivityLog::MENU_CATEGORY_DELETED,
        ], $logs->pluck('action')->all());

        $this->assertSame('Added menu item "Latte" to Drinks', $logs[1]->description);
        $this->assertSame('Edited menu item "Cafe Latte": renamed from "Latte", price 120.00 to 130.00', $logs[2]->description);
        $this->assertSame('Made menu item "Cafe Latte" unavailable at Main Branch', $logs[3]->description);
        $this->assertSame($this->mainBranch->branch_id, $logs[3]->branch_id);
        $this->assertSame($this->otherBranch->branch_id, $logs[4]->branch_id);
        $this->assertSame('Edited menu category "Coffee": renamed from "Drinks"', $logs[5]->description);
        $this->assertNull($logs[0]->branch_id); // cafe-wide
        $this->assertTrue($logs->every(fn ($l) => $l->actor_role === 'Cafe Owner'));
    }

    public function test_manager_sees_their_branch_and_cafe_wide_menu_changes_only(): void
    {
        $owner = $this->tokenFor($this->owner);

        // Cashier signs in on a main-branch register (logged at the main branch).
        $this->device();

        // Owner adds an item (cafe-wide) and switches it off at each branch.
        $item = $this->api('POST', '/api/owner/menu-items', $owner, [
            'menu_name' => 'Mocha', 'base_price' => 140, 'recipes' => [['ingredient_name' => 'Milk', 'quantity' => 150, 'unit' => 'ml']],
        ])->assertSuccessful()->json('item.uuid');
        $this->api('PATCH', "/api/owner/menu-items/$item/branches/{$this->mainBranch->uuid}", $owner, ['is_available' => false])->assertOk();
        $this->api('PATCH', "/api/owner/menu-items/$item/branches/{$this->otherBranch->uuid}", $owner, ['is_available' => false])->assertOk();

        // Owner adds an employee at the other branch: not the manager's business.
        $this->api('POST', $this->base('owner', $this->otherBranch) . '/staff', $owner, [
            'firstname' => 'Ana', 'lastname' => 'Cruz', 'email' => 'ana@test.local', 'role' => 'Staff',
            'phone_number' => '09171234567', 'address' => 'Davao City',
        ])->assertCreated();

        // Owner's own dashboard sign-in: cafe-wide auth, hidden from managers.
        app(\App\Services\ActivityLogService::class)->login($this->owner);

        $res = $this->api('GET', $this->base('manager') . '/activity-logs', $this->tokenFor($this->manager))->assertOk();

        $this->assertEqualsCanonicalizing(
            [ActivityLog::POS_UNLOCK, ActivityLog::MENU_ITEM_CREATED, ActivityLog::MENU_ITEM_AVAILABILITY],
            collect($res->json('logs'))->pluck('action')->all()
        );
        $res->assertJsonPath('counts.all', 3)
            ->assertJsonPath('counts.menu', 2)
            ->assertJsonPath('counts.employees', 0)
            ->assertJsonPath('filters.branch.uuid', $this->mainBranch->uuid);
        $this->assertTrue(collect($res->json('logs'))->every(fn ($l) => $l['ip_address'] === null));

        // The `branch` filter can't widen a manager's view to another branch.
        $this->api('GET', $this->base('manager') . "/activity-logs?branch={$this->otherBranch->uuid}", $this->tokenFor($this->manager))
            ->assertOk()->assertJsonPath('counts.all', 3);

        // Another branch's log stays closed to them.
        $this->api('GET', $this->base('manager', $this->otherBranch) . '/activity-logs', $this->tokenFor($this->manager))->assertForbidden();

        // A cashier has no dashboard access to it at all.
        $this->api('GET', $this->base('manager') . '/activity-logs', $this->tokenFor($this->cashier))->assertForbidden();
    }
}
