<?php

namespace Tests\Feature;

use App\Models\Feature;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Setting a new PIN for a cashier notifies the branch's managers and the owner,
 * except whoever made the change.
 */
class CashierPinNoticeTest extends FloorPlanTestCase
{
    private User $otherManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriptionPlan->features()->attach(
            Feature::create(['key' => 'staff_management', 'name' => 'staff_management', 'is_active' => true])->feature_id
        );

        $this->otherManager = $this->makeStaff('Manager', $this->mainBranch);
    }

    private function resetCashierPin(User $actor, string $role, string $pin = '654321'): void
    {
        $this->api('PUT', $this->base($role) . "/staff/{$this->cashier->uuid}/pin", $this->tokenFor($actor), ['pin' => $pin])
            ->assertOk();

        $this->assertTrue(Hash::check($pin, $this->cashier->fresh()->pin_hash));
    }

    private function kinds(User $user): array
    {
        return $user->fresh()->notifications->pluck('data.kind')->all();
    }

    public function test_owner_changing_a_cashier_pin_notifies_the_branch_managers(): void
    {
        $this->resetCashierPin($this->owner, 'owner');

        $this->assertSame(['pin_changed'], $this->kinds($this->manager));
        $this->assertSame(['pin_changed'], $this->kinds($this->otherManager));
        $this->assertSame([], $this->kinds($this->owner));
        $this->assertSame([], $this->kinds($this->cashier));

        $notice     = $this->manager->fresh()->notifications->first()->data;
        $cashier    = trim("{$this->cashier->firstname} {$this->cashier->lastname}");
        $ownerName  = trim("{$this->owner->firstname} {$this->owner->lastname}");
        $this->assertSame("{$cashier}'s PIN was changed", $notice['title']);
        $this->assertSame("{$ownerName} (Owner) set a new register PIN for {$cashier} (Cashier) at Main Branch.", $notice['body']);
        $this->assertSame("/manager/cafe?branch={$this->mainBranch->uuid}&tab=employees&employee={$this->cashier->uuid}", $notice['link']);
        $this->assertStringNotContainsString('654321', json_encode($notice));
    }

    public function test_manager_changing_a_cashier_pin_notifies_the_owner_and_other_managers(): void
    {
        $this->resetCashierPin($this->manager, 'manager');

        $this->assertSame([], $this->kinds($this->manager));
        $this->assertSame(['pin_changed'], $this->kinds($this->otherManager));
        $this->assertSame(['pin_changed'], $this->kinds($this->owner));

        $notice = $this->owner->fresh()->notifications->first()->data;
        $this->assertStringContainsString('(Manager) set a new register PIN for', $notice['body']);
        $this->assertSame("/owner/branches/{$this->mainBranch->uuid}?tab=employees&employee={$this->cashier->uuid}", $notice['link']);
    }

    public function test_resetting_a_managers_pin_sends_no_cashier_notice(): void
    {
        $this->api('PUT', $this->base('owner') . "/staff/{$this->manager->uuid}/pin", $this->tokenFor($this->owner), ['pin' => '777888'])
            ->assertOk();

        $this->assertSame([], $this->kinds($this->otherManager));
        $this->assertSame([], $this->kinds($this->manager));
    }
}
