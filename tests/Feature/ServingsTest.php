<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\CategoryBranch;
use App\Models\Feature;
use App\Models\IngredientConsumptionLog;
use App\Models\InventoryServing;
use App\Models\MenuBranch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Today's servings of a branch (manager + owner dashboards).
 */
class ServingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cafe $cafe;
    private CafeBranch $mainBranch;
    private CafeBranch $otherBranch;
    private User $manager; // assigned to main branch only
    private SubscriptionPlan $plan;
    private MenuCategory $category;
    private MenuItem $latte;
    private MenuItem $croissant;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }

        $this->owner = $this->makeUser('Cafe Owner');
        $this->cafe  = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Test Cafe']);

        $this->mainBranch  = $this->makeBranch($this->cafe, 'Main Branch');
        $this->otherBranch = $this->makeBranch($this->cafe, 'Second Branch');

        $this->plan = SubscriptionPlan::create(['sub_name' => 'Pro', 'price' => 0, 'max_branches' => 5, 'is_active' => true]);
        foreach (['menu_management', 'pos_system'] as $key) {
            $this->plan->features()->attach(Feature::create(['key' => $key, 'name' => $key, 'is_active' => true])->feature_id);
        }
        Subscription::create([
            'uuid'        => (string) Str::uuid(),
            'user_id'     => $this->owner->user_id,
            'sub_plan_id' => $this->plan->sub_plan_id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addMonth(),
            'status'      => 'active',
        ]);

        $this->manager = $this->makeUser('Manager');
        CafeStaff::create(['user_id' => $this->manager->user_id, 'branch_id' => $this->mainBranch->branch_id, 'employment_status' => 'active']);

        $this->category  = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Drinks', 'is_available' => true]);
        $this->latte     = $this->makeItem('Latte', $this->category);
        $this->croissant = $this->makeItem('Croissant');
    }

    public function test_manager_adds_and_lists_todays_servings(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $this->latte->uuid, 'expected_servings' => 30])
            ->assertCreated()
            ->assertJsonPath('serving.menu_item.menu_name', 'Latte')
            ->assertJsonPath('serving.expected_servings', 30)
            ->assertJsonPath('serving.remaining', 30)
            ->assertJsonPath('serving.date', today()->toDateString());

        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $this->croissant->uuid, 'expected_servings' => 12])->assertCreated();

        $this->api('GET', $this->url(), $token)
            ->assertOk()
            ->assertJsonPath('date', today()->toDateString())
            ->assertJsonCount(2, 'servings')
            ->assertJsonPath('servings.0.menu_item.menu_name', 'Croissant')
            ->assertJson(['summary' => ['items' => 2, 'expected_servings' => 42, 'servings_sold' => 0]]);

        // Other branches keep their own list.
        $this->assertSame(0, InventoryServing::where('branch_id', $this->otherBranch->branch_id)->count());
    }

    public function test_cannot_add_duplicate_foreign_or_unavailable_items(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $this->latte->uuid, 'expected_servings' => 5])->assertCreated();
        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $this->latte->uuid, 'expected_servings' => 5])
            ->assertStatus(422)->assertJsonPath('message', 'This menu item is already in today\'s servings.');

        $strangerCafe = Cafe::create(['user_id' => $this->makeUser('Cafe Owner')->user_id, 'cafe_name' => 'Other']);
        $foreign      = MenuItem::create(['cafe_id' => $strangerCafe->cafe_id, 'menu_name' => 'Mocha', 'base_price' => 100, 'is_available' => true]);
        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $foreign->uuid, 'expected_servings' => 5])->assertNotFound();

        MenuBranch::create(['branch_id' => $this->mainBranch->branch_id, 'men_item_id' => $this->croissant->men_item_id, 'is_available' => false]);
        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $this->croissant->uuid, 'expected_servings' => 5])->assertStatus(422);

        $tea = $this->makeItem('Tea', $this->category);
        CategoryBranch::create(['branch_id' => $this->mainBranch->branch_id, 'men_category_id' => $this->category->men_category_id, 'is_available' => false]);
        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $tea->uuid, 'expected_servings' => 5])->assertStatus(422);

        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $this->latte->uuid, 'expected_servings' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('expected_servings');
    }

    public function test_available_items_excludes_added_and_unavailable(): void
    {
        $token = $this->tokenFor($this->manager);
        $tea   = $this->makeItem('Tea');
        MenuBranch::create(['branch_id' => $this->mainBranch->branch_id, 'men_item_id' => $tea->men_item_id, 'is_available' => false]);

        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $this->latte->uuid, 'expected_servings' => 5])->assertCreated();

        $this->api('GET', $this->url('/available-items'), $token)
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.uuid', $this->croissant->uuid);
    }

    public function test_update_validates_against_sold_and_spoilage(): void
    {
        $token   = $this->tokenFor($this->manager);
        $serving = $this->makeServing($this->latte, ['expected_servings' => 20, 'servings_sold' => 8]);
        $url     = $this->url("/{$serving->uuid}");

        $this->api('PATCH', $url, $token, ['expected_servings' => 25, 'spoilage_qty' => 2])
            ->assertOk()
            ->assertJsonPath('serving.expected_servings', 25)
            ->assertJsonPath('serving.remaining', 15);

        $this->api('PATCH', $url, $token, ['expected_servings' => 9])->assertStatus(422); // 8 sold + 2 spoiled
        $this->api('PATCH', $url, $token, ['spoilage_qty' => 18])->assertStatus(422);

        $this->api('PATCH', $url, $token, ['is_sold_out' => true])->assertOk()->assertJsonPath('serving.is_sold_out', true);

        $this->api('PATCH', $url, $token, [])->assertStatus(422);
        $this->api('PATCH', $url, $token, ['servings_sold' => 0])->assertStatus(422); // POS-owned, not accepted

        $this->assertSame(8, $serving->fresh()->servings_sold);
    }

    public function test_item_is_sold_out_automatically_when_servings_are_used_up(): void
    {
        $token   = $this->tokenFor($this->manager);
        $serving = $this->makeServing($this->latte, ['expected_servings' => 10, 'servings_sold' => 7]);
        $url     = $this->url("/{$serving->uuid}");

        $this->api('PATCH', $url, $token, ['spoilage_qty' => 3])
            ->assertOk()
            ->assertJsonPath('serving.remaining', 0)
            ->assertJsonPath('serving.is_sold_out', true)
            ->assertJsonPath('serving.sold_out_reason', 'depleted');

        $this->api('GET', $this->url(), $token)->assertJsonPath('summary.sold_out', 1);

        // Can't switch it back on without adding servings.
        $this->api('PATCH', $url, $token, ['is_sold_out' => false])->assertStatus(422);

        $this->api('PATCH', $url, $token, ['expected_servings' => 15])
            ->assertOk()
            ->assertJsonPath('serving.remaining', 5)
            ->assertJsonPath('serving.is_sold_out', false)
            ->assertJsonPath('serving.sold_out_reason', null);

        // Manual flag still works while servings remain.
        $this->api('PATCH', $url, $token, ['is_sold_out' => true])
            ->assertJsonPath('serving.sold_out_reason', 'manual');
    }

    public function test_pos_device_sees_todays_servings_with_sold_out_state(): void
    {
        $this->makeServing($this->latte, ['expected_servings' => 5, 'servings_sold' => 5]);
        $this->makeServing($this->croissant, ['expected_servings' => 5]);
        $this->makeServing($this->latte, ['branch_id' => $this->otherBranch->branch_id, 'expected_servings' => 5]);

        $deviceToken = $this->api('POST', '/api/pos/setup', $this->tokenFor($this->manager), [
            'branch_uuid' => $this->mainBranch->uuid,
            'name'        => 'Front Counter',
        ])->assertCreated()->json('device_token');

        $this->api('GET', '/api/pos/device/servings', $deviceToken)
            ->assertOk()
            ->assertJsonCount(2, 'servings')
            ->assertJsonPath('servings.0.menu_item.menu_name', 'Croissant')
            ->assertJsonPath('servings.0.is_sold_out', false)
            ->assertJsonPath('servings.1.menu_item.menu_name', 'Latte')
            ->assertJsonPath('servings.1.is_sold_out', true)
            ->assertJsonPath('servings.1.sold_out_reason', 'depleted');

        // A dashboard login token is not a register.
        $this->api('GET', '/api/pos/device/servings', $this->tokenFor($this->manager))->assertUnauthorized();
    }

    public function test_delete_only_without_sales(): void
    {
        $token = $this->tokenFor($this->manager);
        $sold  = $this->makeServing($this->latte, ['expected_servings' => 10, 'servings_sold' => 1]);
        $fresh = $this->makeServing($this->croissant, ['expected_servings' => 10]);

        $this->api('DELETE', $this->url("/{$sold->uuid}"), $token)->assertStatus(422);
        $this->api('DELETE', $this->url("/{$fresh->uuid}"), $token)->assertOk();

        $this->assertDatabaseHas('inventory_servings', ['uuid' => $sold->uuid]);
        $this->assertDatabaseMissing('inventory_servings', ['uuid' => $fresh->uuid]);
    }

    public function test_past_days_are_not_visible_or_editable(): void
    {
        $token     = $this->tokenFor($this->manager);
        $yesterday = $this->makeServing($this->latte, ['date' => today()->subDay()->toDateString(), 'expected_servings' => 10]);

        $this->api('GET', $this->url(), $token)->assertOk()->assertJsonCount(0, 'servings');
        $this->api('PATCH', $this->url("/{$yesterday->uuid}"), $token, ['expected_servings' => 5])->assertNotFound();
        $this->api('DELETE', $this->url("/{$yesterday->uuid}"), $token)->assertNotFound();

        // The same item can be planned again today.
        $this->api('POST', $this->url(), $token, ['menu_item_uuid' => $this->latte->uuid, 'expected_servings' => 5])->assertCreated();
    }

    public function test_access_is_branch_and_plan_scoped(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->api('GET', $this->url('', $this->otherBranch), $token)->assertForbidden();

        $otherServing = $this->makeServing($this->latte, ['branch_id' => $this->otherBranch->branch_id]);
        $this->api('PATCH', $this->url("/{$otherServing->uuid}"), $token, ['expected_servings' => 5])->assertNotFound();

        // Owner uses the same endpoints for any of their branches.
        $this->api('GET', "/api/owner/branches/{$this->otherBranch->uuid}/servings", $this->tokenFor($this->owner))
            ->assertOk()->assertJsonCount(1, 'servings');

        $this->plan->features()->detach();
        $this->api('GET', $this->url(), $token)->assertForbidden();
    }

    public function test_categories_overview_rolls_up_today_and_filters(): void
    {
        $token  = $this->tokenFor($this->manager);
        $bakery = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Bakery', 'is_available' => true]);
        $tart   = $this->makeItem('Tart', $bakery);

        $this->makeServing($this->latte, ['expected_servings' => 70, 'servings_sold' => 18, 'spoilage_qty' => 2]);
        $this->makeServing($tart, ['expected_servings' => 10, 'servings_sold' => 10]);
        $this->makeServing($this->croissant, ['expected_servings' => 5, 'is_sold_out' => true]);

        $this->api('GET', $this->url('/categories'), $token)
            ->assertOk()
            ->assertJsonCount(3, 'categories.data')
            ->assertJsonPath('categories.data.0.name', 'Bakery')
            ->assertJsonPath('categories.data.0.status', 'sold_out')
            ->assertJsonPath('categories.data.1.name', 'Drinks')
            ->assertJsonPath('categories.data.1.remaining', 50)
            ->assertJsonPath('categories.data.1.expected_servings', 70)
            ->assertJsonPath('categories.data.1.status', 'available')
            ->assertJsonPath('categories.data.2.name', 'Uncategorized')
            ->assertJsonPath('categories.data.2.status', 'sold_out');

        $this->api('GET', $this->url('/categories?search=drin&sort=remaining_desc&per_page=1'), $token)
            ->assertOk()->assertJsonCount(1, 'categories.data')->assertJsonPath('categories.total', 1);

        $this->api('GET', $this->url('/categories?sort=bogus'), $token)->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    public function test_categories_overview_lists_visible_categories_without_servings(): void
    {
        $token = $this->tokenFor($this->manager);
        MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Bakery', 'is_available' => true]);
        MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Hidden', 'is_available' => false]);
        $shown = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Shown here', 'is_available' => false]);
        $off   = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Off here', 'is_available' => true]);
        CategoryBranch::create(['branch_id' => $this->mainBranch->branch_id, 'men_category_id' => $shown->men_category_id, 'is_available' => true]);
        CategoryBranch::create(['branch_id' => $this->mainBranch->branch_id, 'men_category_id' => $off->men_category_id, 'is_available' => false]);

        $this->api('GET', $this->url('/categories'), $token)
            ->assertOk()
            ->assertJsonPath('categories.total', 3)
            ->assertJsonPath('categories.data.0.name', 'Bakery')
            ->assertJsonPath('categories.data.0.expected_servings', 0)
            ->assertJsonPath('categories.data.0.status', 'sold_out')
            ->assertJsonPath('categories.data.1.name', 'Drinks')
            ->assertJsonPath('categories.data.2.name', 'Shown here');
    }

    public function test_ingredients_used_sums_todays_completed_sales_only(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->sell($this->latte, 2, [['Espresso', 'shots', 2], ['Milk', 'cups', 1.5], ['Salt', 'pinch', 1]]);
        $this->sell($this->latte, 1, [['Espresso', 'shots', 1]]);
        $this->sell($this->latte, 1, [['Espresso', 'shots', 9]], ['status' => 'voided']);
        $this->sell($this->latte, 1, [['Espresso', 'shots', 9]], ['created_at' => now()->subDay()]);
        $this->sell($this->latte, 1, [['Espresso', 'shots', 9]], ['branch_id' => $this->otherBranch->branch_id]);

        $this->api('GET', $this->url('/ingredients-used'), $token)
            ->assertOk()
            ->assertJsonCount(2, 'ingredients')
            ->assertJsonPath('ingredients.0', ['name' => 'Espresso', 'unit' => 'shots', 'quantity' => 3, 'percent' => 100])
            ->assertJsonPath('ingredients.1.name', 'Milk')
            ->assertJsonPath('ingredients.1.percent', 50);

        $this->api('GET', $this->url('/ingredients-used?search=mil&sort=quantity_asc'), $token)
            ->assertOk()->assertJsonCount(1, 'ingredients');
    }

    public function test_log_lists_todays_sold_lines_newest_first(): void
    {
        $token = $this->tokenFor($this->manager);

        $this->sell($this->latte, 2);
        $this->sell($this->croissant, 1);
        $this->sell($this->latte, 1, [], ['created_at' => now()->subDay()]);

        $this->api('GET', $this->url('/log'), $token)
            ->assertOk()
            ->assertJsonCount(2, 'log.data')
            ->assertJsonPath('log.data.0.menu_name', 'Croissant')
            ->assertJsonPath('log.data.0.quantity', 1)
            ->assertJsonPath('log.data.1.menu_name', 'Latte')
            ->assertJsonPath('log.data.1.quantity', 2);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{0: string, 1: string, 2: float|int}>  $ingredients  name, unit, quantity
     */
    private function sell(MenuItem $item, int $qty, array $ingredients = [], array $transaction = []): TransactionItem
    {
        $created = $transaction['created_at'] ?? null;
        unset($transaction['created_at']);

        $tx = Transaction::create([
            'branch_id'    => $this->mainBranch->branch_id,
            'total_amount' => 120 * $qty,
            ...$transaction,
        ]);

        if ($created) {
            $tx->forceFill(['created_at' => $created])->save();
        }

        $line = TransactionItem::create([
            'transaction_id' => $tx->transaction_id,
            'men_item_id'    => $item->men_item_id,
            'quantity'       => $qty,
            'unit_price'     => 120,
        ]);

        foreach ($ingredients as [$name, $unit, $quantity]) {
            IngredientConsumptionLog::create([
                'transaction_item_id' => $line->transaction_item_id,
                'ingredient_name'     => $name,
                'quantity_consumed'   => $quantity,
                'unit'                => $unit,
            ]);
        }

        return $line;
    }

    private function url(string $suffix = '', ?CafeBranch $branch = null): string
    {
        $branch ??= $this->mainBranch;

        return "/api/manager/branches/{$branch->uuid}/servings{$suffix}";
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

    private function makeServing(MenuItem $item, array $overrides = []): InventoryServing
    {
        return InventoryServing::create([
            'men_item_id' => $item->men_item_id,
            'branch_id'   => $this->mainBranch->branch_id,
            'date'        => today()->toDateString(),
            ...$overrides,
        ]);
    }

    private function makeItem(string $name, ?MenuCategory $category = null): MenuItem
    {
        return MenuItem::create([
            'cafe_id'         => $this->cafe->cafe_id,
            'men_category_id' => $category?->men_category_id,
            'menu_name'       => $name,
            'base_price'      => 120,
            'is_available'    => true,
        ]);
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
