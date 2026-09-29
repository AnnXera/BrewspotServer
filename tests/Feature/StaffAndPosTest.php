<?php

namespace Tests\Feature;

use App\Mail\StaffAccountCreatedMail;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\Feature;
use App\Models\PosDevice;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\StaffPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Employee management (owner vs manager) and POS register flow.
 *
 * Uses real Sanctum tokens (not actingAs) so the POS device token is
 * exercised through the actual guard.
 */
class StaffAndPosTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private CafeBranch $mainBranch;
    private CafeBranch $otherBranch;
    private User $manager;      // assigned to main branch only
    private User $cashier;      // assigned to main branch
    private User $otherCashier; // assigned to other branch

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier'] as $name) {
            Role::create(['role_name' => $name]);
        }

        $this->owner = $this->makeUser('Cafe Owner', ['email' => 'owner@test.local', 'password_hash' => Hash::make('secret')]);

        $cafe = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Test Cafe']);

        $this->mainBranch  = $this->makeBranch($cafe, 'Main Branch');
        $this->otherBranch = $this->makeBranch($cafe, 'Second Branch');

        $plan = SubscriptionPlan::create(['sub_name' => 'Pro', 'price' => 0, 'max_branches' => 5, 'is_active' => true]);
        foreach (['staff_management', 'pos_system'] as $key) {
            $plan->features()->attach(Feature::create(['key' => $key, 'name' => $key, 'is_active' => true])->feature_id);
        }
        Subscription::create([
            'uuid'        => (string) Str::uuid(),
            'user_id'     => $this->owner->user_id,
            'sub_plan_id' => $plan->sub_plan_id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addMonth(),
            'status'      => 'active',
        ]);

        $this->manager      = $this->makeStaff('Manager', $this->mainBranch, ['email' => 'manager@test.local', 'password_hash' => Hash::make('secret')]);
        $this->cashier      = $this->makeStaff('Cashier', $this->mainBranch);
        $this->otherCashier = $this->makeStaff('Cashier', $this->otherBranch);

        app(StaffPinService::class)->setPin($this->manager, '9999');
        app(StaffPinService::class)->setPin($this->cashier, '1234');
        app(StaffPinService::class)->setPin($this->otherCashier, '4321');
    }

    // ── Branch access ────────────────────────────────────────────────────

    public function test_manager_can_only_open_branches_they_are_assigned_to(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->api('GET', "/api/manager/branches/{$this->mainBranch->uuid}/staff", $token)->assertOk();
        $this->api('GET', "/api/manager/branches/{$this->otherBranch->uuid}/staff", $token)->assertForbidden();

        $this->api('GET', '/api/manager/branches', $token)
            ->assertOk()
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('branches.0.uuid', $this->mainBranch->uuid);
    }

    public function test_owner_cannot_open_another_owners_branch(): void
    {
        $stranger       = $this->makeUser('Cafe Owner', ['email' => 'other@test.local']);
        $strangerCafe   = Cafe::create(['user_id' => $stranger->user_id, 'cafe_name' => 'Other Cafe']);
        $strangerBranch = $this->makeBranch($strangerCafe, 'Elsewhere');

        $this->api('GET', "/api/owner/branches/{$strangerBranch->uuid}/staff", $this->tokenFor($this->owner))
            ->assertForbidden();
    }

    // ── Creating staff ───────────────────────────────────────────────────

    public function test_owner_adds_cashier_without_email_and_no_setup_email_is_sent(): void
    {
        $response = $this->api('POST', "/api/owner/branches/{$this->mainBranch->uuid}/staff", $this->tokenFor($this->owner), [
            'firstname' => 'Ana',
            'lastname'  => 'Reyes',
            'role'      => 'Cashier',
            'pin'       => '2468',
            'hired_at'  => '2026-01-20',
        ]);

        $response->assertCreated()
            ->assertJsonPath('staff.role', 'Cashier')
            ->assertJsonPath('staff.email', null)
            ->assertJsonPath('staff.pin_set', true)
            ->assertJsonPath('staff.account_status', 'active')
            ->assertJsonPath('staff.assignment.hired_at', '2026-01-20');

        Mail::assertNothingOutgoing();
    }

    public function test_cashier_requires_a_pin_and_manager_requires_an_email(): void
    {
        $token = $this->tokenFor($this->owner);
        $url   = "/api/owner/branches/{$this->mainBranch->uuid}/staff";

        $this->api('POST', $url, $token, ['firstname' => 'A', 'lastname' => 'B', 'role' => 'Cashier'])
            ->assertStatus(422)->assertJsonValidationErrors('pin');

        $this->api('POST', $url, $token, ['firstname' => 'A', 'lastname' => 'B', 'role' => 'Manager'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_owner_adds_manager_and_setup_email_is_sent(): void
    {
        $this->api('POST', "/api/owner/branches/{$this->mainBranch->uuid}/staff", $this->tokenFor($this->owner), [
            'firstname' => 'Sofia',
            'lastname'  => 'Miles',
            'email'     => 'sofia@test.local',
            'role'      => 'Manager',
        ])->assertCreated()->assertJsonPath('staff.account_status', 'pending_setup');

        Mail::assertQueued(StaffAccountCreatedMail::class);
    }

    public function test_manager_can_add_cashier_but_not_manager(): void
    {
        $token = $this->tokenFor($this->manager);
        $url   = "/api/manager/branches/{$this->mainBranch->uuid}/staff";

        $this->api('POST', $url, $token, ['firstname' => 'New', 'lastname' => 'Cashier', 'role' => 'Cashier', 'pin' => '1111'])
            ->assertCreated();

        $this->api('POST', $url, $token, ['firstname' => 'New', 'lastname' => 'Boss', 'role' => 'Manager', 'email' => 'boss@test.local'])
            ->assertForbidden();
    }

    // ── Managers vs managers ─────────────────────────────────────────────

    public function test_manager_sees_other_managers_read_only(): void
    {
        $peer  = $this->makeStaff('Manager', $this->mainBranch, ['email' => 'peer@test.local']);
        $token = $this->tokenFor($this->manager);
        $base  = "/api/manager/branches/{$this->mainBranch->uuid}/staff/{$peer->uuid}";

        $this->api('GET', $base, $token)->assertOk()->assertJsonPath('staff.can_manage', false);
        $this->api('PATCH', $base, $token, ['firstname' => 'Changed'])->assertForbidden();
        $this->api('POST', "$base/terminate", $token)->assertForbidden();
        $this->api('PUT', "$base/pin", $token, ['pin' => '0000'])->assertForbidden();
        $this->api('PUT', "$base/schedule", $token, ['schedule' => [['day_of_week' => 1, 'is_day_off' => true]]])->assertForbidden();
    }

    public function test_only_owner_can_change_a_role(): void
    {
        $url = "/api/manager/branches/{$this->mainBranch->uuid}/staff/{$this->cashier->uuid}";

        $this->api('PATCH', $url, $this->tokenFor($this->manager), ['role' => 'Manager', 'email' => 'promo@test.local'])
            ->assertForbidden();

        $this->api('PATCH', "/api/owner/branches/{$this->mainBranch->uuid}/staff/{$this->cashier->uuid}", $this->tokenFor($this->owner), [
            'role'  => 'Manager',
            'email' => 'promo@test.local',
        ])->assertOk()->assertJsonPath('staff.role', 'Manager')->assertJsonPath('staff.account_status', 'pending_setup');

        Mail::assertQueued(StaffAccountCreatedMail::class);
    }

    // ── Terminate ────────────────────────────────────────────────────────

    public function test_manager_terminates_cashier_which_revokes_access_and_ends_pos_session(): void
    {
        $device = $this->registerDevice($this->manager, $this->mainBranch);
        $this->api('POST', "/api/pos/device/staff/{$this->cashier->uuid}/unlock", $device, ['pin' => '1234'])->assertOk();

        $this->api('POST', "/api/manager/branches/{$this->mainBranch->uuid}/staff/{$this->cashier->uuid}/terminate", $this->tokenFor($this->manager))
            ->assertOk();

        $assignment = CafeStaff::where('user_id', $this->cashier->user_id)->first();
        $this->assertSame('terminated', $assignment->employment_status);
        $this->assertNotNull($assignment->terminated_at);
        $this->assertNull(PosDevice::first()->active_staff_id);

        // Gone from the lock screen, and can't unlock any more.
        $names = collect($this->api('GET', '/api/pos/device/staff', $device)->json('staff'))->pluck('uuid');
        $this->assertNotContains($this->cashier->uuid, $names);
        $this->api('POST', "/api/pos/device/staff/{$this->cashier->uuid}/unlock", $device, ['pin' => '1234'])->assertNotFound();
    }

    public function test_terminated_manager_loses_dashboard_access(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->api('POST', "/api/owner/branches/{$this->mainBranch->uuid}/staff/{$this->manager->uuid}/terminate", $this->tokenFor($this->owner))
            ->assertOk();

        $this->api('GET', '/api/manager/branches', $token)->assertUnauthorized();
    }

    // ── Schedule ─────────────────────────────────────────────────────────

    public function test_schedule_validation_and_opening_hour_warnings(): void
    {
        $url   = "/api/manager/branches/{$this->mainBranch->uuid}/staff/{$this->cashier->uuid}/schedule";
        $token = $this->tokenFor($this->manager);

        $this->api('PUT', $url, $token, ['schedule' => [
            ['day_of_week' => 1, 'is_day_off' => false, 'start_time' => '17:00', 'end_time' => '09:00'],
        ]])->assertStatus(422)->assertJsonValidationErrors('schedule.0.end_time');

        // Cafe::created seeds weekends as closed and weekdays 09:00-17:00.
        $response = $this->api('PUT', $url, $token, ['schedule' => [
            ['day_of_week' => 0, 'is_day_off' => false, 'start_time' => '10:00', 'end_time' => '14:00'],
            ['day_of_week' => 1, 'is_day_off' => false, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['day_of_week' => 2, 'is_day_off' => false, 'start_time' => '08:00', 'end_time' => '12:00'],
            ['day_of_week' => 6, 'is_day_off' => true],
        ]])->assertOk();

        $response->assertJsonCount(4, 'staff.schedule')
            ->assertJsonPath('staff.schedule.1.start_time', '09:00')
            ->assertJsonCount(2, 'warnings'); // Sunday closed + Tuesday starts early
    }

    // ── POS register ─────────────────────────────────────────────────────

    public function test_manager_registers_device_only_for_their_branch(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->api('GET', '/api/pos/setup/branches', $token)->assertOk()->assertJsonCount(1, 'branches');

        $this->api('POST', '/api/pos/setup', $token, ['branch_uuid' => $this->otherBranch->uuid, 'name' => 'Counter'])
            ->assertForbidden();

        $this->api('POST', '/api/pos/setup', $token, ['branch_uuid' => $this->mainBranch->uuid, 'name' => 'Counter'])
            ->assertCreated()
            ->assertJsonStructure(['device_token', 'device' => ['uuid', 'branch_uuid']]);

        // The setup login is dropped after registering.
        $this->api('GET', '/api/manager/branches', $token)->assertUnauthorized();
    }

    public function test_cashier_unlocks_with_pin_without_manager_approval(): void
    {
        $device = $this->registerDevice($this->owner, $this->mainBranch);

        $this->api('GET', '/api/pos/device/staff', $device)->assertOk()->assertJsonCount(2, 'staff'); // manager + cashier

        $this->api('POST', "/api/pos/device/staff/{$this->cashier->uuid}/unlock", $device, ['pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('staff.uuid', $this->cashier->uuid);

        $this->api('GET', '/api/pos/device', $device)->assertJsonPath('device.active_staff.uuid', $this->cashier->uuid);

        $this->api('POST', '/api/pos/device/lock', $device)->assertOk();
        $this->api('GET', '/api/pos/device', $device)->assertJsonPath('device.active_staff', null);
    }

    public function test_cashier_from_another_branch_cannot_unlock(): void
    {
        $device = $this->registerDevice($this->owner, $this->mainBranch);

        $this->api('POST', "/api/pos/device/staff/{$this->otherCashier->uuid}/unlock", $device, ['pin' => '4321'])
            ->assertNotFound();
    }

    public function test_pin_locks_after_five_failures_and_reset_unlocks_it(): void
    {
        $device = $this->registerDevice($this->owner, $this->mainBranch);
        $url    = "/api/pos/device/staff/{$this->cashier->uuid}/unlock";

        for ($i = 1; $i < StaffPinService::MAX_ATTEMPTS; $i++) {
            $this->api('POST', $url, $device, ['pin' => '0000'])->assertStatus(422);
        }
        $this->api('POST', $url, $device, ['pin' => '0000'])->assertStatus(423);

        // Correct PIN no longer works while locked.
        $this->api('POST', $url, $device, ['pin' => '1234'])->assertStatus(423);

        $this->api('PUT', "/api/manager/branches/{$this->mainBranch->uuid}/staff/{$this->cashier->uuid}/pin", $this->tokenFor($this->manager), ['pin' => '5678'])
            ->assertOk();

        $this->api('POST', $url, $device, ['pin' => '5678'])->assertOk();
    }

    public function test_device_and_user_tokens_cannot_cross_over(): void
    {
        $device = $this->registerDevice($this->owner, $this->mainBranch);

        // Device token can't reach dashboards or setup.
        $this->api('GET', "/api/owner/branches/{$this->mainBranch->uuid}/staff", $device)->assertForbidden();
        $this->api('GET', '/api/manager/branches', $device)->assertForbidden();
        $this->api('POST', '/api/pos/setup', $device, ['branch_uuid' => $this->mainBranch->uuid, 'name' => 'X'])->assertForbidden();

        // User token can't act as a register.
        $this->api('GET', '/api/pos/device/staff', $this->tokenFor($this->manager))->assertUnauthorized();
    }

    public function test_removed_device_must_be_set_up_again(): void
    {
        $device     = $this->registerDevice($this->owner, $this->mainBranch);
        $deviceUuid = PosDevice::first()->uuid;

        $this->api('GET', "/api/manager/branches/{$this->mainBranch->uuid}/pos-devices", $this->tokenFor($this->manager))
            ->assertOk()->assertJsonCount(1, 'devices');

        $this->api('DELETE', "/api/manager/branches/{$this->mainBranch->uuid}/pos-devices/{$deviceUuid}", $this->tokenFor($this->manager))
            ->assertOk();

        $this->api('GET', '/api/pos/device', $device)->assertUnauthorized();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function api(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        // Guards cache the resolved user per app instance; reset so each call
        // authenticates with the token it was given.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $data);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function registerDevice(User $by, CafeBranch $branch): string
    {
        return $this->api('POST', '/api/pos/setup', $this->tokenFor($by), [
            'branch_uuid' => $branch->uuid,
            'name'        => 'Front Counter',
        ])->assertCreated()->json('device_token');
    }

    private function makeUser(string $role, array $overrides = []): User
    {
        return User::create([
            'firstname'         => fake()->firstName(),
            'lastname'          => fake()->lastName(),
            'email'             => null,
            'status'            => 'active',
            'email_verified_at' => now(),
            'role_id'           => Role::where('role_name', $role)->value('role_id'),
            ...$overrides,
        ]);
    }

    private function makeStaff(string $role, CafeBranch $branch, array $overrides = []): User
    {
        $user = $this->makeUser($role, $overrides);

        CafeStaff::create([
            'user_id'           => $user->user_id,
            'branch_id'         => $branch->branch_id,
            'employment_status' => 'active',
        ]);

        return $user;
    }

    private function makeBranch(Cafe $cafe, string $name): CafeBranch
    {
        return CafeBranch::create([
            'cafe_id'          => $cafe->cafe_id,
            'branch_name'      => $name,
            'cafe_email'       => Str::slug($name) . '-' . Str::random(4) . '@test.local',
            'cafe_phonenumber' => '+639170000000',
            'address'          => 'Davao City',
            'branch_type'      => 'main',
            'status'           => 'active',
        ]);
    }
}
