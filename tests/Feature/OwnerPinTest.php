<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Owners set a register PIN (it approves voids and refunds on the register), like managers:
 * during password setup, and later from their profile.
 */
class OwnerPinTest extends FloorPlanTestCase
{
    public function test_owner_password_setup_requires_a_pin(): void
    {
        $owner = $this->makeUser('Cafe Owner', ['status' => 'approved', 'password_hash' => null]);
        $cafe  = Cafe::create(['user_id' => $owner->user_id, 'cafe_name' => 'New Cafe']);
        $this->makeBranch($cafe, 'New Branch');

        $password = ['password' => 'Secret#123', 'password_confirmation' => 'Secret#123'];

        $this->postJson("/api/auth/setup-password/{$owner->uuid}", $password)
            ->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->postJson("/api/auth/setup-password/{$owner->uuid}", [...$password, 'pin' => '246810', 'pin_confirmation' => '246811'])
            ->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->assertSame('approved', $owner->fresh()->status);

        $this->postJson("/api/auth/setup-password/{$owner->uuid}", [...$password, 'pin' => '246810', 'pin_confirmation' => '246810'])
            ->assertOk()->assertJsonPath('success', true);

        $owner->refresh();
        $this->assertSame('active', $owner->status);
        $this->assertTrue(Hash::check('246810', $owner->pin_hash));
        $this->assertFalse($owner->mustChangePin());
    }

    public function test_active_owner_sets_their_pin_from_the_profile(): void
    {
        $token = $this->tokenFor($this->owner);

        $this->api('GET', '/api/user/profile', $token)->assertOk()->assertJsonPath('user.pin_set', false);

        $this->api('PUT', '/api/user/pin', $token, ['current_password' => 'wrong', 'pin' => '112233'])
            ->assertStatus(422)->assertJsonPath('message', 'Your current password is incorrect.');
        $this->api('PUT', '/api/user/pin', $token, ['current_password' => 'secret', 'pin' => '1122'])
            ->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->assertFalse($this->owner->fresh()->hasPin());

        $this->api('PUT', '/api/user/pin', $token, ['current_password' => 'secret', 'pin' => '112233'])
            ->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('112233', $this->owner->fresh()->pin_hash));
        $this->api('GET', '/api/user/profile', $token)->assertOk()->assertJsonPath('user.pin_set', true);

        // The register now lists the owner as an approver with a PIN.
        $approvers = collect($this->api('GET', '/api/pos/device/approving-managers', $this->device())->assertOk()->json('managers'));
        $this->assertTrue($approvers->firstWhere('uuid', $this->owner->uuid)['has_pin']);
    }

    public function test_a_manager_changing_their_own_pin_notifies_the_owner(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->api('PUT', '/api/user/pin', $token, ['current_password' => 'secret', 'pin' => '445566'])->assertOk();
        $this->api('PUT', '/api/manager/pin', $token, ['current_password' => 'secret', 'pin' => '556677'])->assertOk();

        $notices = $this->owner->fresh()->notifications;
        $this->assertCount(2, $notices);
        $this->assertSame(['pin_changed', 'pin_changed'], $notices->pluck('data.kind')->all());

        $name = trim("{$this->manager->firstname} {$this->manager->lastname}");
        $this->assertSame("{$name} changed their PIN", $notices[0]->data['title']);
        $this->assertSame("/owner/branches/{$this->mainBranch->uuid}?tab=employees&employee={$this->manager->uuid}", $notices[0]->data['link']);

        // A wrong password changes nothing and notifies nobody.
        $this->api('PUT', '/api/user/pin', $token, ['current_password' => 'wrong', 'pin' => '667788'])->assertStatus(422);
        $this->assertCount(2, $this->owner->fresh()->notifications);

        // The manager is not notified about their own change.
        $this->assertCount(0, $this->manager->fresh()->notifications);

        // Both changes are in the activity log under Employees, at the manager's branch, without the PIN.
        $logs = \App\Models\ActivityLog::where('action', \App\Models\ActivityLog::EMPLOYEE_PIN_CHANGED)->get();
        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn ($log) => $log->category === 'employees'
            && $log->branch_id === $this->mainBranch->branch_id
            && $log->user_id === $this->manager->user_id
            && $log->description === 'Changed their own register PIN'
            && $log->meta['employee']['uuid'] === $this->manager->uuid));
        $this->assertStringNotContainsString('556677', json_encode($logs->pluck('meta')));

        $this->api('GET', '/api/owner/activity-logs?from=2026-10-05&to=2026-10-05&category=employees', $this->tokenFor($this->owner))
            ->assertOk()->assertJsonPath('counts.employees', 2)->assertJsonPath('logs.0.action', 'employee.pin_changed');
    }

    public function test_a_manager_replacing_a_temporary_pin_on_the_register_notifies_the_owner(): void
    {
        app(\App\Services\StaffPinService::class)->setPin($this->manager, '111111', temporary: true);

        $this->api('POST', "/api/pos/device/staff/{$this->manager->uuid}/change-pin", $this->device(false), [
            'current_pin' => '111111',
            'new_pin'     => '222222',
            'new_pin_confirmation' => '222222',
        ])->assertOk();

        $this->assertSame(['pin_changed'], $this->owner->fresh()->notifications->pluck('data.kind')->all());

        $log = \App\Models\ActivityLog::where('action', \App\Models\ActivityLog::EMPLOYEE_PIN_CHANGED)->sole();
        $this->assertSame('Replaced their temporary register PIN on the register', $log->description);
        $this->assertSame('register', $log->meta['where']);
    }

    public function test_an_owner_changing_their_pin_notifies_nobody_and_is_not_logged(): void
    {
        $this->api('PUT', '/api/user/pin', $this->tokenFor($this->owner), ['current_password' => 'secret', 'pin' => '112233'])->assertOk();

        $this->assertSame(0, \Illuminate\Notifications\DatabaseNotification::count());
        $this->assertSame(0, \App\Models\ActivityLog::where('category', 'employees')->count());
    }

    public function test_managers_can_use_the_same_endpoint_and_cashiers_cannot(): void
    {
        $this->api('PUT', '/api/user/pin', $this->tokenFor($this->manager), ['current_password' => 'secret', 'pin' => '445566'])->assertOk();
        $this->assertTrue(Hash::check('445566', $this->manager->fresh()->pin_hash));

        $this->cashier->update(['password_hash' => Hash::make('secret')]);
        $this->api('PUT', '/api/user/pin', $this->tokenFor($this->cashier), ['current_password' => 'secret', 'pin' => '778899'])->assertForbidden();
        $this->assertTrue(Hash::check('123456', User::find($this->cashier->user_id)->pin_hash));
    }
}
