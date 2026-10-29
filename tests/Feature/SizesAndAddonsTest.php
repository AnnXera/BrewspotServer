<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\Feature;
use App\Models\IngredientConsumptionLog;
use App\Models\InventoryServing;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TransactionItem;
use App\Models\User;
use App\Services\StaffPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Menu items in sizes (Small/Large, own price and ingredient amounts) and
 * add-ons (Oreo crumbles +20, up to a per-drink limit), from the owner's menu
 * to the register's checkout.
 */
class SizesAndAddonsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cafe $cafe;
    private CafeBranch $branch;
    private User $cashier;
    private string $token;

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

        $this->branch = CafeBranch::create([
            'cafe_id'          => $this->cafe->cafe_id,
            'branch_name'      => 'Main Branch',
            'cafe_email'       => 'main-' . Str::random(4) . '@test.local',
            'cafe_phonenumber' => '+639170000000',
            'address'          => 'Davao City',
            'branch_type'      => 'main',
            'status'           => 'active',
        ]);

        $this->cashier = $this->makeUser('Cashier');
        CafeStaff::create(['user_id' => $this->cashier->user_id, 'branch_id' => $this->branch->branch_id, 'employment_status' => 'active']);
        app(StaffPinService::class)->setPin($this->cashier, '123456');

        $this->token = $this->owner->createToken('test')->plainTextToken;
    }

    // ── Menu management ─────────────────────────────────────────────────

    public function test_item_with_sizes_takes_the_lowest_price_and_per_size_recipes(): void
    {
        $oreo = $this->createOreo()->json('addon.uuid');

        $item = $this->createIcedCaramel([$oreo])
            ->assertCreated()
            ->assertJsonPath('item.base_price', '95.00')
            ->assertJsonCount(2, 'item.sizes')
            ->assertJsonPath('item.sizes.0.name', 'Small')
            ->assertJsonPath('item.sizes.1.price', '120.00')
            ->assertJsonPath('item.addons.0.uuid', $oreo)
            ->json('item');

        $recipes = collect($item['recipes']);
        $this->assertNull($recipes->firstWhere('ingredient_name', 'Caramel Syrup')['size_uuid']);
        $this->assertSame(
            [$item['sizes'][0]['uuid'], $item['sizes'][1]['uuid']],
            $recipes->where('ingredient_name', 'Milk')->pluck('size_uuid')->values()->all()
        );

        // Milk is used by one item, though it has a row per size.
        $milk = collect($this->api('GET', '/api/owner/ingredients')->json('ingredients'))->firstWhere('name', 'Milk');
        $this->assertSame(1, $milk['used_in']);
        $this->assertSame(['Iced Caramel'], $milk['used_in_names']);

        $oreoIngredient = collect($this->api('GET', '/api/owner/ingredients')->json('ingredients'))->firstWhere('name', 'Oreo');
        $this->assertSame(['Oreo Crumbles'], $oreoIngredient['used_in_names']);
    }

    public function test_size_rules_are_enforced(): void
    {
        $this->api('POST', '/api/owner/menu-items', [
            'menu_name' => 'Latte',
            'sizes'     => [['name' => 'Regular', 'price' => 100]],
            'recipes'   => [['ingredient_name' => 'Milk', 'quantity' => 150, 'unit' => 'ml']],
        ])->assertStatus(422)->assertJsonValidationErrors('sizes');

        $this->api('POST', '/api/owner/menu-items', [
            'menu_name' => 'Latte',
            'sizes'     => [['name' => 'Small', 'price' => 100], ['name' => 'small', 'price' => 120]],
            'recipes'   => [['ingredient_name' => 'Milk', 'quantity' => 150, 'unit' => 'ml']],
        ])->assertStatus(422)->assertJsonValidationErrors('sizes.1.name');

        // An ingredient for all sizes and again for one size would be counted twice.
        $this->api('POST', '/api/owner/menu-items', [
            'menu_name' => 'Latte',
            'sizes'     => [['name' => 'Small', 'price' => 100], ['name' => 'Large', 'price' => 120]],
            'recipes'   => [
                ['ingredient_name' => 'Milk', 'quantity' => 150, 'unit' => 'ml'],
                ['ingredient_name' => 'Milk', 'quantity' => 220, 'unit' => 'ml', 'size_index' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('recipes.1.ingredient_name');

        // Set for one size only: every size needs it, or it goes on All sizes.
        $this->api('POST', '/api/owner/menu-items', [
            'menu_name' => 'Americano',
            'sizes'     => [['name' => 'Small', 'price' => 90], ['name' => 'Large', 'price' => 110]],
            'recipes'   => [
                ['ingredient_name' => 'Hot Water', 'quantity' => 1, 'unit' => 'cups'],
                ['ingredient_name' => 'Espresso', 'quantity' => 2, 'unit' => 'shots', 'size_index' => 0],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'recipes.1.size_index' => 'Espresso is set for Small but not Large. Add it for Large, or use All sizes.',
        ]);

        // Without sizes, a price is still required.
        $this->api('POST', '/api/owner/menu-items', [
            'menu_name' => 'Latte',
            'recipes'   => [['ingredient_name' => 'Milk', 'quantity' => 150, 'unit' => 'ml']],
        ])->assertStatus(422)->assertJsonValidationErrors('base_price');
    }

    public function test_editing_sizes_keeps_existing_rows_and_turning_sizes_off_removes_them(): void
    {
        $item  = $this->createIcedCaramel()->json('item');
        $small = $item['sizes'][0];

        $updated = $this->api('PATCH', "/api/owner/menu-items/{$item['uuid']}", [
            'sizes' => [
                ['uuid' => $small['uuid'], 'name' => 'Small (12oz)', 'price' => 99],
                ['name' => 'Medium', 'price' => 110],
            ],
        ])->assertOk()->json('item');

        $this->assertSame($small['uuid'], $updated['sizes'][0]['uuid']);
        $this->assertSame('Small (12oz)', $updated['sizes'][0]['name']);
        $this->assertSame('99.00', $updated['base_price']);
        // Large's milk row went with Large; Small's stayed.
        $this->assertCount(1, collect($updated['recipes'])->where('ingredient_name', 'Milk'));

        $plain = $this->api('PATCH', "/api/owner/menu-items/{$item['uuid']}", [
            'sizes'      => [],
            'base_price' => 105,
            'recipes'    => [['ingredient_name' => 'Milk', 'quantity' => 180, 'unit' => 'ml']],
        ])->assertOk()->json('item');

        $this->assertSame([], $plain['sizes']);
        $this->assertSame('105.00', $plain['base_price']);
    }

    public function test_addons_are_managed_per_cafe(): void
    {
        $this->createOreo()
            ->assertCreated()
            ->assertJsonPath('addon.name', 'Oreo Crumbles')
            ->assertJsonPath('addon.max_quantity', 2)
            ->assertJsonPath('addon.recipes.0.ingredient_name', 'Oreo');

        $this->createOreo()->assertStatus(422)->assertJsonValidationErrors('name');

        $uuid = $this->api('GET', '/api/owner/menu-addons')->assertOk()->assertJsonCount(1, 'addons')->json('addons.0.uuid');

        $this->api('PATCH', "/api/owner/menu-addons/{$uuid}", ['price' => 25])->assertOk()->assertJsonPath('addon.price', '25.00');
        $this->api('DELETE', "/api/owner/menu-addons/{$uuid}")->assertOk();
        $this->api('GET', '/api/owner/menu-addons')->assertOk()->assertJsonCount(0, 'addons');
    }

    // ── Register ────────────────────────────────────────────────────────

    public function test_checkout_prices_sizes_and_addons_and_deducts_their_ingredients(): void
    {
        $oreo = $this->createOreo()->json('addon.uuid');
        $item = $this->createIcedCaramel([$oreo])->json('item');
        $this->stock($item['uuid'], 10);

        $device = $this->unlockedDevice();

        $menuItem = collect($this->deviceApi('GET', '/api/pos/device/menu', $device)->json('items'))->firstWhere('uuid', $item['uuid']);
        $this->assertCount(2, $menuItem['sizes']);
        $this->assertSame(20.0, (float) $menuItem['addons'][0]['price']);

        $large = $item['sizes'][1]['uuid'];

        // 2 Large with double Oreo: (120 + 2 x 20) x 2 = 320
        $this->deviceApi('POST', '/api/pos/device/checkout', $device, [
            'items'           => [['uuid' => $item['uuid'], 'quantity' => 2, 'size_uuid' => $large, 'addons' => [['uuid' => $oreo, 'quantity' => 2]]]],
            'payment_method'  => 'cash',
            'amount_tendered' => 320,
        ])->assertOk();

        $line = TransactionItem::with('addons', 'transaction')->sole();
        $this->assertEquals(160, $line->unit_price);
        $this->assertEquals(320, $line->transaction->total_amount);
        $this->assertSame('Large', $line->size_name);
        $this->assertSame('Oreo Crumbles', $line->addons->sole()->addon_name);
        $this->assertSame(2, $line->addons->sole()->quantity);

        $used = IngredientConsumptionLog::all()->mapWithKeys(fn ($l) => [$l->ingredient_name => (float) $l->quantity_consumed]);
        $this->assertSame(['Caramel Syrup' => 60.0, 'Milk' => 440.0, 'Oreo' => 60.0], $used->sortKeys()->all()); // 30x2, 220x2, 15x2x2

        // Sizes share the item's servings: 2 sold, 8 left.
        $this->assertSame(2, InventoryServing::sole()->servings_sold);
    }

    public function test_checkout_rejects_missing_size_and_addons_over_the_limit_or_not_offered(): void
    {
        $oreo  = $this->createOreo()->json('addon.uuid');
        $other = $this->api('POST', '/api/owner/menu-addons', ['name' => 'Extra Shot', 'price' => 30])->json('addon.uuid');
        $item  = $this->createIcedCaramel([$oreo])->json('item');
        $this->stock($item['uuid'], 10);

        $device = $this->unlockedDevice();
        $small  = $item['sizes'][0]['uuid'];

        $checkout = fn (array $line) => $this->deviceApi('POST', '/api/pos/device/checkout', $device, [
            'items' => [['uuid' => $item['uuid'], 'quantity' => 1, ...$line]], 'payment_method' => 'cash', 'amount_tendered' => 1000,
        ]);

        $checkout([])->assertStatus(422)->assertJsonPath('message', 'Pick a size for Iced Caramel.');
        $checkout(['size_uuid' => $small, 'addons' => [['uuid' => $oreo, 'quantity' => 3]]])->assertStatus(422);
        $checkout(['size_uuid' => $small, 'addons' => [['uuid' => $other, 'quantity' => 1]]])->assertStatus(422);
        $checkout(['size_uuid' => $small, 'addons' => [['uuid' => $oreo, 'quantity' => 1]]])->assertOk();

        $this->assertEquals(115, TransactionItem::sole()->unit_price); // 95 + 20
    }

    // ── Hot and iced ─────────────────────────────────────────────────────

    public function test_hot_and_iced_item_needs_an_iced_price_per_size(): void
    {
        $this->api('POST', '/api/owner/menu-items', [
            'menu_name'       => 'Coffee',
            'is_hot_and_iced' => true,
            'sizes'           => [['name' => 'Small', 'price' => 150], ['name' => 'Large', 'price' => 160, 'iced_price' => 180]],
            'recipes'         => [['ingredient_name' => 'Espresso', 'quantity' => 1, 'unit' => 'shots']],
        ])->assertStatus(422)->assertJsonValidationErrors('sizes.0.iced_price');

        $this->api('POST', '/api/owner/menu-items', [
            'menu_name'       => 'Americano',
            'is_hot_and_iced' => '1', // as form data sends it
            'base_price'      => 100,
            'recipes'         => [['ingredient_name' => 'Espresso', 'quantity' => 1, 'unit' => 'shots']],
        ])->assertStatus(422)->assertJsonValidationErrors('iced_price');

        $item = $this->createCoffee()
            ->assertCreated()
            ->assertJsonPath('item.is_hot_and_iced', true)
            ->assertJsonPath('item.base_price', '150.00')
            ->assertJsonPath('item.sizes.0.iced_price', '170.00')
            ->assertJsonPath('item.sizes.1.iced_price', '180.00')
            ->json('item');

        // Turning it off drops the iced prices.
        $this->api('PATCH', "/api/owner/menu-items/{$item['uuid']}", [
            'is_hot_and_iced' => false,
            'sizes'           => collect($item['sizes'])->map(fn ($s) => ['uuid' => $s['uuid'], 'name' => $s['name'], 'price' => $s['price'], 'iced_price' => $s['iced_price']])->all(),
        ])->assertOk()
            ->assertJsonPath('item.is_hot_and_iced', false)
            ->assertJsonPath('item.sizes.0.iced_price', null);
    }

    public function test_checkout_prices_hot_and_iced_but_uses_the_same_ingredients(): void
    {
        $item  = $this->createCoffee()->json('item');
        $latte = $this->createIcedCaramel()->json('item');
        $this->stock($item['uuid'], 10);
        $this->stock($latte['uuid'], 10);

        $device = $this->unlockedDevice();
        [$small, $large] = array_column($item['sizes'], 'uuid');

        $menuItem = collect($this->deviceApi('GET', '/api/pos/device/menu', $device)->json('items'))->firstWhere('uuid', $item['uuid']);
        $this->assertTrue($menuItem['is_hot_and_iced']);
        $this->assertSame(170.0, (float) $menuItem['sizes'][0]['iced_price']);

        $checkout = fn (array $lines) => $this->deviceApi('POST', '/api/pos/device/checkout', $device, [
            'items' => $lines, 'payment_method' => 'cash', 'amount_tendered' => 1000,
        ]);

        $checkout([['uuid' => $item['uuid'], 'quantity' => 1, 'size_uuid' => $small]])
            ->assertStatus(422)->assertJsonPath('message', 'Pick hot or iced for Coffee.');

        $checkout([
            ['uuid' => $item['uuid'], 'quantity' => 1, 'size_uuid' => $small, 'temperature' => 'hot'],
            ['uuid' => $item['uuid'], 'quantity' => 1, 'size_uuid' => $large, 'temperature' => 'hot'],
            ['uuid' => $item['uuid'], 'quantity' => 1, 'size_uuid' => $small, 'temperature' => 'iced'],
            ['uuid' => $item['uuid'], 'quantity' => 1, 'size_uuid' => $large, 'temperature' => 'iced'],
        ])->assertOk();

        $lines = TransactionItem::orderBy('transaction_item_id')->get();
        $this->assertSame([150.0, 160.0, 170.0, 180.0], $lines->map(fn ($l) => (float) $l->unit_price)->all());
        $this->assertSame(['hot', 'hot', 'iced', 'iced'], $lines->pluck('temperature')->all());

        // Ingredients follow the size only: Small 150ml, Large 220ml, hot or iced.
        $milk = IngredientConsumptionLog::where('ingredient_name', 'Milk')->sum('quantity_consumed');
        $this->assertEquals(150 + 220 + 150 + 220, $milk);

        // An item not sold both ways refuses a temperature.
        $checkout([['uuid' => $latte['uuid'], 'quantity' => 1, 'size_uuid' => $latte['sizes'][0]['uuid'], 'temperature' => 'iced']])
            ->assertStatus(422);
    }

    public function test_hot_and_iced_item_without_sizes(): void
    {
        $item = $this->api('POST', '/api/owner/menu-items', [
            'menu_name'       => 'Americano',
            'is_hot_and_iced' => true,
            'base_price'      => 100,
            'iced_price'      => 115,
            'recipes'         => [['ingredient_name' => 'Espresso', 'quantity' => 2, 'unit' => 'shots']],
        ])->assertCreated()->assertJsonPath('item.iced_price', '115.00')->json('item');
        $this->stock($item['uuid'], 10);

        $this->deviceApi('POST', '/api/pos/device/checkout', $this->unlockedDevice(), [
            'items'           => [['uuid' => $item['uuid'], 'quantity' => 2, 'temperature' => 'iced']],
            'payment_method'  => 'cash',
            'amount_tendered' => 230,
        ])->assertOk();

        $this->assertEquals(115, TransactionItem::sole()->unit_price);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createCoffee(): TestResponse
    {
        return $this->api('POST', '/api/owner/menu-items', [
            'menu_name'       => 'Coffee',
            'is_hot_and_iced' => true,
            'sizes'           => [
                ['name' => 'Small', 'price' => 150, 'iced_price' => 170],
                ['name' => 'Large', 'price' => 160, 'iced_price' => 180],
            ],
            'recipes'         => [
                ['ingredient_name' => 'Espresso', 'quantity' => 1, 'unit' => 'shots'],
                ['ingredient_name' => 'Milk', 'quantity' => 150, 'unit' => 'ml', 'size_index' => 0],
                ['ingredient_name' => 'Milk', 'quantity' => 220, 'unit' => 'ml', 'size_index' => 1],
            ],
        ]);
    }

    private function createOreo(): TestResponse
    {
        return $this->api('POST', '/api/owner/menu-addons', [
            'name'         => 'Oreo Crumbles',
            'price'        => 20,
            'max_quantity' => 2,
            'recipes'      => [['ingredient_name' => 'Oreo', 'quantity' => 15, 'unit' => 'g']],
        ]);
    }

    private function createIcedCaramel(array $addonUuids = []): TestResponse
    {
        return $this->api('POST', '/api/owner/menu-items', [
            'menu_name'   => 'Iced Caramel',
            'sizes'       => [['name' => 'Small', 'price' => 95], ['name' => 'Large', 'price' => 120]],
            'recipes'     => [
                ['ingredient_name' => 'Caramel Syrup', 'quantity' => 30, 'unit' => 'ml'],
                ['ingredient_name' => 'Milk', 'quantity' => 150, 'unit' => 'ml', 'size_index' => 0],
                ['ingredient_name' => 'Milk', 'quantity' => 220, 'unit' => 'ml', 'size_index' => 1],
            ],
            'addon_uuids' => $addonUuids,
        ]);
    }

    private function stock(string $itemUuid, int $servings): void
    {
        $serving = InventoryServing::create([
            'men_item_id'       => MenuItem::where('uuid', $itemUuid)->value('men_item_id'),
            'branch_id'         => $this->branch->branch_id,
            'date'              => today()->toDateString(),
            'expected_servings' => $servings,
        ]);

        // SQLite keeps the date cast as "Y-m-d 00:00:00", which checkout's
        // firstOrCreate on a plain date doesn't match. MySQL's DATE column does.
        DB::table('inventory_servings')->where($serving->getKeyName(), $serving->getKey())->update(['date' => today()->toDateString()]);
    }

    private function unlockedDevice(): string
    {
        $device = $this->api('POST', '/api/pos/setup', ['branch_uuid' => $this->branch->uuid, 'name' => 'Front Counter'])
            ->assertCreated()
            ->json('device_token');

        $this->deviceApi('POST', "/api/pos/device/staff/{$this->cashier->uuid}/unlock", $device, ['pin' => '123456'])->assertOk();

        return $device;
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->deviceApi($method, $uri, $this->token, $data);
    }

    private function deviceApi(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $data);
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
}
