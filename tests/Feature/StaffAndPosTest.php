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

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
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

    // ── Employees tab list + stats ───────────────────────────────────────

    public function test_employee_list_filters_sorts_and_counts(): void
    {
        $token = $this->tokenFor($this->owner);
        $url   = "/api/owner/branches/{$this->mainBranch->uuid}/staff";

        $this->cashier->update(['firstname' => 'Ana', 'lastname' => 'Reyes']);
        $gone = $this->makeStaff('Cashier', $this->mainBranch, ['firstname' => 'Aaron', 'lastname' => 'Gone']);
        CafeStaff::where('user_id', $gone->user_id)->update(['employment_status' => 'terminated']);
        $away = $this->makeStaff('Cashier', $this->mainBranch, ['firstname' => 'Abby', 'lastname' => 'Away']);
        CafeStaff::where('user_id', $away->user_id)->update(['employment_status' => 'suspended']);

        // Active first, terminated last — regardless of alphabetical order.
        $statuses = collect($this->api('GET', $url, $token)->assertOk()->json('staff.data'))
            ->pluck('assignment.employment_status')->all();
        $this->assertSame('active', $statuses[0]);
        $this->assertSame('terminated', end($statuses));

        $this->api('GET', "$url?search=ana reyes", $token)
            ->assertJsonCount(1, 'staff.data')->assertJsonPath('staff.data.0.uuid', $this->cashier->uuid);
        $this->api('GET', "$url?status=terminated", $token)
            ->assertJsonCount(1, 'staff.data')->assertJsonPath('staff.data.0.uuid', $gone->uuid);
        $this->api('GET', "$url?role=Manager", $token)
            ->assertJsonCount(1, 'staff.data')->assertJsonPath('staff.data.0.uuid', $this->manager->uuid);
        $this->api('GET', "$url?status=fired", $token)->assertStatus(422);

        $this->api('GET', "$url/stats", $token)->assertOk()->assertJson(['stats' => [
            'total' => 4, 'active' => 2, 'inactive' => 0, 'suspended' => 1, 'terminated' => 1,
        ]]);
    }

    public function test_employee_list_shows_other_branches(): void
    {
        CafeStaff::create(['user_id' => $this->cashier->user_id, 'branch_id' => $this->otherBranch->branch_id, 'employment_status' => 'active']);

        $row = collect($this->api('GET', "/api/owner/branches/{$this->mainBranch->uuid}/staff", $this->tokenFor($this->owner))->json('staff.data'))
            ->firstWhere('uuid', $this->cashier->uuid);

        $this->assertSame([['uuid' => $this->otherBranch->uuid, 'branch_name' => 'Second Branch']], $row['other_branches']);
    }

    // ── Creating staff ───────────────────────────────────────────────────

    public function test_owner_adds_cashier_and_no_setup_email_is_sent(): void
    {
        $response = $this->api('POST', "/api/owner/branches/{$this->mainBranch->uuid}/staff", $this->tokenFor($this->owner), [
            'firstname' => 'Ana',
            'lastname'  => 'Reyes',
            'email'     => 'ana@test.local',
            'role'      => 'Cashier',
            'pin'       => '2468',
            'hired_at'  => '2026-01-20',
            ...$this->contact(),
        ]);

        // Email is stored for contact only; cashiers get no password setup mail.
        $response->assertCreated()
            ->assertJsonPath('staff.role', 'Cashier')
            ->assertJsonPath('staff.email', 'ana@test.local')
            ->assertJsonPath('staff.pin_set', true)
            ->assertJsonPath('staff.account_status', 'active')
            ->assertJsonPath('staff.assignment.hired_at', '2026-01-20');

        Mail::assertNothingOutgoing();
    }

    public function test_email_phone_address_are_required_and_pin_only_for_register_roles(): void
    {
        $token = $this->tokenFor($this->owner);
        $url   = "/api/owner/branches/{$this->mainBranch->uuid}/staff";

        $this->api('POST', $url, $token, ['firstname' => 'A', 'lastname' => 'B', 'role' => 'Cashier'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'pin', 'phone_number', 'address']);

        $this->api('POST', $url, $token, ['firstname' => 'A', 'lastname' => 'B', 'role' => 'Manager'])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'pin']);

        $this->api('POST', $url, $token, ['firstname' => 'A', 'lastname' => 'B', 'role' => 'Staff'])
            ->assertStatus(422)->assertJsonMissingValidationErrors('pin');
    }

    public function test_phone_must_be_a_ph_mobile_number_and_is_stored_as_plus_63(): void
    {
        $token = $this->tokenFor($this->owner);
        $url   = "/api/owner/branches/{$this->mainBranch->uuid}/staff";
        $base  = ['firstname' => 'A', 'lastname' => 'B', 'role' => 'Staff', 'email' => 'phone@test.local', 'address' => 'Davao City'];

        foreach (['0917123456', '+6391712345678', '0827123456', '+63 817 123 4567'] as $bad) {
            $this->api('POST', $url, $token, [...$base, 'phone_number' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('phone_number');
        }

        // Pasted "09…" numbers are normalised before validation.
        $this->api('POST', $url, $token, [...$base, 'phone_number' => '0917 123 4567'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'phone@test.local', 'phone_number' => '+639171234567']);
    }

    public function test_phone_can_be_changed_but_not_cleared_on_update(): void
    {
        $token = $this->tokenFor($this->owner);
        $url   = "/api/owner/branches/{$this->mainBranch->uuid}/staff/{$this->cashier->uuid}";

        foreach ([null, '', '12345'] as $bad) {
            $this->api('PATCH', $url, $token, ['phone_number' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('phone_number');
        }

        $this->api('PATCH', $url, $token, ['phone_number' => '+639181112222'])->assertOk();
        $this->assertSame('+639181112222', $this->cashier->fresh()->phone_number);
    }

    public function test_staff_are_records_only(): void
    {
        $response = $this->api('POST', "/api/manager/branches/{$this->mainBranch->uuid}/staff", $this->tokenFor($this->manager), [
            'firstname' => 'Bea',
            'lastname'  => 'Barista',
            'email'     => 'bea@test.local',
            'role'      => 'Staff',
            'pin'       => '1111', // ignored for staff
            ...$this->contact(),
        ])->assertCreated()
            ->assertJsonPath('staff.role', 'Staff')
            ->assertJsonPath('staff.pin_set', false)
            ->assertJsonPath('staff.can_manage', true);

        Mail::assertNothingOutgoing();
        $staffUuid = $response->json('staff.uuid');

        // Not on the register, and can't be given a PIN.
        $device = $this->registerDevice($this->owner, $this->mainBranch);
        $this->assertNotContains($staffUuid, collect($this->api('GET', '/api/pos/device/staff', $device)->json('staff'))->pluck('uuid'));
        $this->api('PUT', "/api/owner/branches/{$this->mainBranch->uuid}/staff/{$staffUuid}/pin", $this->tokenFor($this->owner), ['pin' => '2222'])
            ->assertStatus(422);

        // Listed and counted with everyone else.
        $this->api('GET', "/api/owner/branches/{$this->mainBranch->uuid}/staff?role=Staff", $this->tokenFor($this->owner))
            ->assertJsonCount(1, 'staff.data');
    }

    public function test_changing_a_cashier_to_staff_removes_their_pin(): void
    {
        $this->api('PATCH', "/api/owner/branches/{$this->mainBranch->uuid}/staff/{$this->cashier->uuid}", $this->tokenFor($this->owner), ['role' => 'Staff'])
            ->assertOk()->assertJsonPath('staff.role', 'Staff')->assertJsonPath('staff.pin_set', false);
    }

    public function test_owner_adds_manager_with_temporary_pin_and_setup_email_is_sent(): void
    {
        $this->api('POST', "/api/owner/branches/{$this->mainBranch->uuid}/staff", $this->tokenFor($this->owner), [
            'firstname' => 'Sofia',
            'lastname'  => 'Miles',
            'email'     => 'sofia@test.local',
            'role'      => 'Manager',
            'pin'       => '1357',
            ...$this->contact(),
        ])->assertCreated()
            ->assertJsonPath('staff.account_status', 'pending_setup')
            ->assertJsonPath('staff.pin_must_change', true);

        Mail::assertQueued(StaffAccountCreatedMail::class);
    }

    public function test_cashier_pin_set_by_owner_or_manager_is_not_temporary(): void
    {
        $this->api('PUT', "/api/manager/branches/{$this->mainBranch->uuid}/staff/{$this->cashier->uuid}/pin", $this->tokenFor($this->manager), ['pin' => '2222'])
            ->assertOk();

        $this->assertFalse($this->cashier->fresh()->mustChangePin());
    }

    public function test_manager_can_add_cashier_but_not_manager(): void
    {
        $token = $this->tokenFor($this->manager);
        $url   = "/api/manager/branches/{$this->mainBranch->uuid}/staff";

        $this->api('POST', $url, $token, ['firstname' => 'New', 'lastname' => 'Cashier', 'email' => 'new.cashier@test.local', 'role' => 'Cashier', 'pin' => '1111', ...$this->contact()])
            ->assertCreated();

        $this->api('POST', $url, $token, ['firstname' => 'New', 'lastname' => 'Boss', 'role' => 'Manager', 'email' => 'boss@test.local', 'pin' => '2222', ...$this->contact()])
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
        ])->assertOk()
            ->assertJsonPath('staff.role', 'Manager')
            ->assertJsonPath('staff.account_status', 'pending_setup')
            // Their cashier PIN was known to others, so it becomes temporary.
            ->assertJsonPath('staff.pin_must_change', true);

        Mail::assertQueued(StaffAccountCreatedMail::class);
    }

    // ── Temporary manager PIN ────────────────────────────────────────────

    public function test_manager_must_replace_temporary_pin_on_register_before_unlocking(): void
    {
        // Owner resets the manager's PIN → temporary.
        $this->api('PUT', "/api/owner/branches/{$this->mainBranch->uuid}/staff/{$this->manager->uuid}/pin", $this->tokenFor($this->owner), ['pin' => '4444'])
            ->assertOk();
        $this->assertTrue($this->manager->fresh()->mustChangePin());

        $device = $this->registerDevice($this->owner, $this->mainBranch);
        $base   = "/api/pos/device/staff/{$this->manager->uuid}";

        // Correct temporary PIN does not unlock.
        $this->api('POST', "$base/unlock", $device, ['pin' => '4444'])
            ->assertStatus(409)->assertJsonPath('must_change_pin', true);
        $this->api('GET', '/api/pos/device', $device)->assertJsonPath('device.active_staff', null);

        // Wrong current PIN, and reusing the temporary PIN, are rejected.
        $this->api('POST', "$base/change-pin", $device, ['current_pin' => '0000', 'new_pin' => '8642', 'new_pin_confirmation' => '8642'])
            ->assertStatus(422);
        $this->api('POST', "$base/change-pin", $device, ['current_pin' => '4444', 'new_pin' => '4444', 'new_pin_confirmation' => '4444'])
            ->assertStatus(422);

        // Replacing it unlocks the register and clears the flag.
        $this->api('POST', "$base/change-pin", $device, ['current_pin' => '4444', 'new_pin' => '8642', 'new_pin_confirmation' => '8642'])
            ->assertOk()->assertJsonPath('staff.uuid', $this->manager->uuid);

        $this->assertFalse($this->manager->fresh()->mustChangePin());
        $this->api('POST', "$base/unlock", $device, ['pin' => '8642'])->assertOk();
    }

    public function test_manager_can_replace_temporary_pin_from_dashboard(): void
    {
        app(StaffPinService::class)->setPin($this->manager, '4444', temporary: true);

        $token = $this->tokenFor($this->manager);

        $this->api('PUT', '/api/manager/pin', $token, ['current_password' => 'wrong', 'pin' => '8642'])->assertStatus(422);
        $this->api('PUT', '/api/manager/pin', $token, ['current_password' => 'secret', 'pin' => '8642'])->assertOk();

        $this->assertFalse($this->manager->fresh()->mustChangePin());
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

    private function contact(): array
    {
        return ['phone_number' => '09171234567', 'address' => 'Ecoland, Davao City'];
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
            'email'             => fake()->unique()->safeEmail(),
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
