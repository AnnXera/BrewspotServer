<?php

namespace Tests\Feature;

use App\Models\BranchTable;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\Feature;
use App\Models\FloorPlan;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\StaffPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the floor plan + reservation tests.
 *
 * "Now" is frozen on a Monday morning; the default cafe hours are
 * Mon-Fri 09:00-17:00 (Sat/Sun closed), so Tuesday 2026-10-06 is bookable.
 */
abstract class FloorPlanTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Cafe $cafe;
    protected CafeBranch $mainBranch;
    protected CafeBranch $otherBranch;
    protected User $manager;      // main branch only
    protected User $cashier;      // main branch
    protected SubscriptionPlan $subscriptionPlan;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow('2026-10-05 08:00:00'); // Monday

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }

        $this->owner = $this->makeUser('Cafe Owner', ['email' => 'owner@test.local', 'password_hash' => Hash::make('secret')]);
        $this->cafe  = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Test Cafe']);
        $this->cafe->ensureOpeningHours();

        $this->mainBranch  = $this->makeBranch($this->cafe, 'Main Branch');
        $this->otherBranch = $this->makeBranch($this->cafe, 'Second Branch');

        $this->subscriptionPlan = SubscriptionPlan::create(['sub_name' => 'Pro', 'price' => 0, 'max_branches' => 5, 'is_active' => true]);
        foreach (['reservations', 'pos_system'] as $key) {
            $this->subscriptionPlan->features()->attach(Feature::create(['key' => $key, 'name' => $key, 'is_active' => true])->feature_id);
        }
        Subscription::create([
            'uuid'        => (string) Str::uuid(),
            'user_id'     => $this->owner->user_id,
            'sub_plan_id' => $this->subscriptionPlan->sub_plan_id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addMonth(),
            'status'      => 'active',
        ]);

        $this->manager = $this->makeStaff('Manager', $this->mainBranch, ['email' => 'manager@test.local', 'password_hash' => Hash::make('secret')]);
        $this->cashier = $this->makeStaff('Cashier', $this->mainBranch);

        app(StaffPinService::class)->setPin($this->cashier, '123456');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // â”€â”€ Floor plan fixtures â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    protected function makePlan(CafeBranch $branch, string $name = 'Main Floor', bool $active = true): FloorPlan
    {
        return FloorPlan::create([
            'branch_id'      => $branch->branch_id,
            'floorplan_name' => $name,
            'canvas_width'   => 1000,
            'canvas_height'  => 800,
            'is_active'      => $active,
        ]);
    }

    protected function makeTable(FloorPlan $plan, string $name = 'T1', int $capacity = 4): BranchTable
    {
        return $plan->tables()->create([
            'table_name' => $name,
            'capacity'   => $capacity,
            'asset_key'  => 'table_rec_4',
            'x_location' => 100,
            'y_location' => 100,
            'rotation'   => 0,
            'status'     => 'available',
        ]);
    }

    protected function makeReservation(BranchTable $table, string $start, string $end, string $status = 'confirmed'): Reservation
    {
        return Reservation::create([
            'table_id'         => $table->table_id,
            'customer_name'    => 'Walk In',
            'customer_phone'   => '09171234567',
            'party_size'       => 2,
            'reservation_date' => $start,
            'reservation_end'  => $end,
            'status'           => $status,
        ]);
    }

    protected function booking(BranchTable $table, array $overrides = []): array
    {
        return [
            'table_uuid'       => $table->uuid,
            'customer_name'    => 'Juan Dela Cruz',
            'customer_phone'   => '0917 123 4567',
            'party_size'       => 2,
            'reservation_date' => '2026-10-06 10:00:00',
            'reservation_end'  => '2026-10-06 11:30:00',
            ...$overrides,
        ];
    }

    protected function base(string $role, ?CafeBranch $branch = null): string
    {
        return "/api/{$role}/branches/" . ($branch ?? $this->mainBranch)->uuid;
    }

    // â”€â”€ Auth helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    protected function api(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        // Guards cache the resolved user per app instance; reset so each call
        // authenticates with the token it was given.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $data);
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /** A registered register for the main branch; unlocked by the cashier when $unlock is true. */
    protected function device(bool $unlock = true): string
    {
        $token = $this->api('POST', '/api/pos/setup', $this->tokenFor($this->owner), [
            'branch_uuid' => $this->mainBranch->uuid,
            'name'        => 'Front Counter',
        ])->assertCreated()->json('device_token');

        if ($unlock) {
            $this->api('POST', "/api/pos/device/staff/{$this->cashier->uuid}/unlock", $token, ['pin' => '123456'])->assertOk();
        }

        return $token;
    }

    protected function makeUser(string $role, array $overrides = []): User
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

    protected function makeStaff(string $role, CafeBranch $branch, array $overrides = []): User
    {
        $user = $this->makeUser($role, $overrides);

        CafeStaff::create([
            'user_id'           => $user->user_id,
            'branch_id'         => $branch->branch_id,
            'employment_status' => 'active',
        ]);

        return $user;
    }

    protected function makeBranch(Cafe $cafe, string $name): CafeBranch
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
