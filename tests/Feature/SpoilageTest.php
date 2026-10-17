<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\Feature;
use App\Models\InventoryServing;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\ServingSpoilageLog;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\StaffPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Spoiled servings: logged on the register, voided from the dashboard.
 */
class SpoilageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $manager;
    private User $cashier;
    private Cafe $cafe;
    private CafeBranch $branch;
    private CafeBranch $otherBranch;
    private MenuItem $latte;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }

        $this->owner = $this->makeUser('Cafe Owner');
        $this->cafe  = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Test Cafe']);

        $plan = SubscriptionPlan::create(['sub_name' => 'Pro', 'price' => 0, 'max_branches' => 5, 'is_active' => true]);
        foreach (['menu_management', 'pos_system'] as $key) {
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

        $this->branch      = $this->makeBranch('Main Branch');
        $this->otherBranch = $this->makeBranch('Second Branch');

        $this->manager = $this->makeUser('Manager');
        CafeStaff::create(['user_id' => $this->manager->user_id, 'branch_id' => $this->branch->branch_id, 'employment_status' => 'active']);

        $this->cashier = $this->makeUser('Cashier');
        CafeStaff::create(['user_id' => $this->cashier->user_id, 'branch_id' => $this->branch->branch_id, 'employment_status' => 'active']);
        app(StaffPinService::class)->setPin($this->cashier, '123456');

        $this->latte = MenuItem::create(['cafe_id' => $this->cafe->cafe_id, 'menu_name' => 'Latte', 'base_price' => 120, 'is_available' => true]);
    }

    public function test_register_logs_spoilage_and_it_lowers_remaining_stock(): void
    {
        $serving = $this->makeServing(['expected_servings' => 10, 'servings_sold' => 3]);
        $device  = $this->unlockedDevice();

        $this->api('POST', '/api/pos/device/spoilage', $device, ['menu_item_uuid' => $this->latte->uuid, 'quantity' => 2, 'reason' => 'dropped'])
            ->assertCreated()
            ->assertJsonPath('log.quantity', 2)
            ->assertJsonPath('log.reason', 'dropped')
            ->assertJsonPath('log.menu_item.menu_name', 'Latte')
            ->assertJsonPath('log.logged_by', "{$this->cashier->firstname} {$this->cashier->lastname}")
            ->assertJsonPath('log.device_name', 'Front Counter');

        $this->api('POST', '/api/pos/device/spoilage', $device, ['menu_item_uuid' => $this->latte->uuid, 'quantity' => 1])->assertCreated();

        $this->assertSame(3, $serving->fresh()->spoilage_qty);
        $this->assertSame(2, ServingSpoilageLog::count());

        $menuLatte = collect($this->api('GET', '/api/pos/device/menu', $device)->json('items'))->firstWhere('uuid', $this->latte->uuid);
        $this->assertSame(4, $menuLatte['available_stock']); // 10 - 3 sold - 3 spoiled

        $this->api('GET', '/api/pos/device/spoilage', $device)
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'logs')
            ->assertJsonPath('logs.0.quantity', 1); // newest first
    }

    public function test_register_rejects_invalid_spoilage(): void
    {
        $this->makeServing(['expected_servings' => 5, 'servings_sold' => 4]);
        $device = $this->unlockedDevice();
        $log    = fn (array $data) => $this->api('POST', '/api/pos/device/spoilage', $device, $data);

        $log(['menu_item_uuid' => $this->latte->uuid, 'quantity' => 2])->assertStatus(422); // only 1 left
        $log(['menu_item_uuid' => $this->latte->uuid, 'quantity' => 0])->assertStatus(422);
        $log(['menu_item_uuid' => $this->latte->uuid, 'quantity' => 1, 'reason' => 'stolen'])->assertStatus(422);

        $noServing = MenuItem::create(['cafe_id' => $this->cafe->cafe_id, 'menu_name' => 'Mocha', 'base_price' => 120, 'is_available' => true]);
        $log(['menu_item_uuid' => $noServing->uuid, 'quantity' => 1])->assertStatus(422);

        $strangerCafe = Cafe::create(['user_id' => $this->makeUser('Cafe Owner')->user_id, 'cafe_name' => 'Other']);
        $foreign      = MenuItem::create(['cafe_id' => $strangerCafe->cafe_id, 'menu_name' => 'Tea', 'base_price' => 90, 'is_available' => true]);
        $log(['menu_item_uuid' => $foreign->uuid, 'quantity' => 1])->assertNotFound();

        $this->api('POST', '/api/pos/device/lock', $device)->assertOk();
        $log(['menu_item_uuid' => $this->latte->uuid, 'quantity' => 1])->assertForbidden();

        $this->assertSame(0, ServingSpoilageLog::count());
    }

    public function test_manager_lists_and_voids_an_entry(): void
    {
        $serving = $this->makeServing(['expected_servings' => 10]);
        $device  = $this->unlockedDevice();
        $logUuid = $this->api('POST', '/api/pos/device/spoilage', $device, ['menu_item_uuid' => $this->latte->uuid, 'quantity' => 4])->json('log.uuid');
        $this->api('POST', '/api/pos/device/spoilage', $device, ['menu_item_uuid' => $this->latte->uuid, 'quantity' => 1])->assertCreated();

        $token = $this->tokenFor($this->manager);

        $this->api('GET', $this->url(), $token)
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonCount(2, 'logs');

        $this->api('POST', $this->url("/{$logUuid}/void"), $token)
            ->assertOk()
            ->assertJsonPath('log.voided_by', "{$this->manager->firstname} {$this->manager->lastname}");

        $this->assertSame(1, $serving->fresh()->spoilage_qty);

        $this->api('POST', $this->url("/{$logUuid}/void"), $token)->assertStatus(422); // already voided

        $this->api('GET', $this->url(), $token)
            ->assertJsonPath('total', 1)
            ->assertJsonCount(2, 'logs'); // voided entries stay listed
    }

    public function test_cannot_void_another_branchs_or_an_earlier_days_entry(): void
    {
        $device  = $this->unlockedDevice();
        $this->makeServing(['expected_servings' => 10]);
        $logUuid = $this->api('POST', '/api/pos/device/spoilage', $device, ['menu_item_uuid' => $this->latte->uuid, 'quantity' => 1])->json('log.uuid');

        // Owner opens the other branch with this branch's entry.
        $this->api('POST', $this->url("/{$logUuid}/void", $this->otherBranch), $this->tokenFor($this->owner))->assertNotFound();

        $yesterday = InventoryServing::create([
            'men_item_id'       => $this->latte->men_item_id,
            'branch_id'         => $this->branch->branch_id,
            'date'              => today()->subDay()->toDateString(),
            'expected_servings' => 10,
            'spoilage_qty'      => 2,
        ]);
        $old = ServingSpoilageLog::create(['servings_id' => $yesterday->servings_id, 'quantity' => 2]);

        $this->api('POST', $this->url("/{$old->uuid}/void"), $this->tokenFor($this->manager))->assertNotFound();
        $this->assertSame(2, $yesterday->fresh()->spoilage_qty);
    }

    public function test_serving_with_spoilage_cannot_be_removed(): void
    {
        $serving = $this->makeServing(['expected_servings' => 10]);
        $device  = $this->unlockedDevice();
        $this->api('POST', '/api/pos/device/spoilage', $device, ['menu_item_uuid' => $this->latte->uuid, 'quantity' => 1])->assertCreated();

        $this->api('DELETE', "/api/manager/branches/{$this->branch->uuid}/servings/{$serving->uuid}", $this->tokenFor($this->manager))
            ->assertStatus(422);

        $this->assertNotNull($serving->fresh());
    }

    private function makeServing(array $overrides = []): InventoryServing
    {
        return InventoryServing::create([
            'men_item_id' => $this->latte->men_item_id,
            'branch_id'   => $this->branch->branch_id,
            'date'        => today()->toDateString(),
            ...$overrides,
        ]);
    }

    private function unlockedDevice(): string
    {
        $device = $this->api('POST', '/api/pos/setup', $this->tokenFor($this->owner), ['branch_uuid' => $this->branch->uuid, 'name' => 'Front Counter'])
            ->assertCreated()
            ->json('device_token');

        $this->api('POST', "/api/pos/device/staff/{$this->cashier->uuid}/unlock", $device, ['pin' => '123456'])->assertOk();

        return $device;
    }

    private function url(string $suffix = '', ?CafeBranch $branch = null): string
    {
        $branch ??= $this->branch;
        $role   = $branch->is($this->branch) ? 'manager' : 'owner';

        return "/api/{$role}/branches/{$branch->uuid}/servings/spoilage{$suffix}";
    }

    private function api(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $data);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'firstname'         => fake()->firstName(),
            'lastname'          => fake()->lastName(),
            'email'             => fake()->unique()->safeEmail(),
            'status'            => 'active',
            'email_verified_at' => now(),
            'role_id'           => Role::where('role_name', $role)->value('role_id'),
        ]);
    }

    private function makeBranch(string $name): CafeBranch
    {
        return CafeBranch::create([
            'cafe_id'          => $this->cafe->cafe_id,
            'branch_name'      => $name,
            'cafe_email'       => Str::slug($name) . '-' . Str::random(4) . '@test.local',
            'cafe_phonenumber' => '+639170000000',
            'address'          => 'Davao City',
            'branch_type'      => 'main',
            'status'           => 'active',
        ]);
    }
}
