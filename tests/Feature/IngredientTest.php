<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\Feature;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Recipe ingredients come from one list per cafe, so the same ingredient
 * can't be spelled two ways or added twice to a recipe.
 */
class IngredientTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cafe $cafe;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }

        [$this->owner, $this->cafe] = $this->makeOwnerWithCafe('owner@test.local');
        $this->token = $this->owner->createToken('test')->plainTextToken;
    }

    public function test_typed_names_reuse_the_same_ingredient_ignoring_case_and_spacing(): void
    {
        $this->createItem('Latte', [['ingredient_name' => 'Oat Milk', 'quantity' => 200, 'unit' => 'ml']])->assertCreated();
        $this->createItem('Flat White', [['ingredient_name' => '  oat   MILK ', 'quantity' => 150, 'unit' => 'ml']])
            ->assertCreated()
            ->assertJsonPath('item.recipes.0.ingredient_name', 'Oat Milk');

        $this->assertSame(1, Ingredient::count());
        $this->assertSame(2, Ingredient::first()->recipes()->count());

        $this->api('GET', '/api/owner/ingredients')
            ->assertOk()
            ->assertJsonCount(1, 'ingredients')
            ->assertJsonPath('ingredients.0.name', 'Oat Milk')
            ->assertJsonPath('ingredients.0.unit', 'ml');
    }

    public function test_the_same_ingredient_twice_in_one_recipe_is_rejected(): void
    {
        $this->createItem('Latte', [
            ['ingredient_name' => 'Milk', 'quantity' => 200, 'unit' => 'ml'],
            ['ingredient_name' => 'MILK', 'quantity' => 50,  'unit' => 'ml'],
        ])->assertStatus(422)->assertJsonValidationErrors('recipes.1.ingredient_name');

        // Picked by uuid in one row and typed in another is still a duplicate.
        $milk = Ingredient::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Milk', 'unit' => 'ml']);

        $this->createItem('Latte', [
            ['ingredient_uuid' => $milk->uuid, 'quantity' => 200, 'unit' => 'ml'],
            ['ingredient_name' => 'milk',      'quantity' => 50,  'unit' => 'ml'],
        ])->assertStatus(422)->assertJsonValidationErrors('recipes.1.ingredient_name');

        $this->assertSame(0, MenuItem::count());
    }

    public function test_recipe_unit_must_match_the_ingredients_unit(): void
    {
        Ingredient::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Milk', 'unit' => 'ml']);

        $this->createItem('Latte', [['ingredient_name' => 'Milk', 'quantity' => 0.2, 'unit' => 'l']])
            ->assertStatus(422)
            ->assertJsonPath('errors', ['recipes.0.unit' => ['Milk is measured in ml.']]);

        $this->createItem('Latte', [['ingredient_name' => 'Milk', 'quantity' => 1, 'unit' => 'bucket']])
            ->assertStatus(422)->assertJsonValidationErrors('recipes.0.unit');
    }

    public function test_ingredients_are_picked_by_uuid_within_the_owners_cafe_only(): void
    {
        [, $otherCafe] = $this->makeOwnerWithCafe('other@test.local');
        $theirs = Ingredient::create(['cafe_id' => $otherCafe->cafe_id, 'name' => 'Milk', 'unit' => 'ml']);
        $ours   = Ingredient::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Espresso', 'unit' => 'shots']);

        $this->createItem('Latte', [['ingredient_uuid' => $theirs->uuid, 'quantity' => 200, 'unit' => 'ml']])
            ->assertStatus(422)->assertJsonValidationErrors('recipes.0.ingredient_uuid');

        $this->createItem('Americano', [['ingredient_uuid' => $ours->uuid, 'quantity' => 2, 'unit' => 'shots']])
            ->assertCreated()
            ->assertJsonPath('item.recipes.0.ingredient_uuid', $ours->uuid)
            ->assertJsonPath('item.recipes.0.ingredient_name', 'Espresso');

        // The list never shows another cafe's ingredients.
        $this->api('GET', '/api/owner/ingredients')->assertJsonCount(1, 'ingredients');
    }

    public function test_updating_a_recipe_replaces_its_rows(): void
    {
        $uuid = $this->createItem('Latte', [['ingredient_name' => 'Milk', 'quantity' => 200, 'unit' => 'ml']])
            ->json('item.uuid');

        $this->api('PATCH', "/api/owner/menu-items/{$uuid}", ['recipes' => [
            ['ingredient_name' => 'Milk',     'quantity' => 180, 'unit' => 'ml'],
            ['ingredient_name' => 'Espresso', 'quantity' => 2,   'unit' => 'shots'],
        ]])->assertOk()->assertJsonCount(2, 'item.recipes');

        $this->assertSame(2, Ingredient::count());
    }

    public function test_search_and_retired_ingredients(): void
    {
        Ingredient::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Oat Milk', 'unit' => 'ml']);
        Ingredient::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Espresso', 'unit' => 'shots']);
        $old = Ingredient::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Soy Milk', 'unit' => 'ml', 'is_active' => false]);

        $this->api('GET', '/api/owner/ingredients?search=MILK')
            ->assertJsonCount(1, 'ingredients')->assertJsonPath('ingredients.0.name', 'Oat Milk');

        // Typing a retired ingredient's name brings it back instead of duplicating it.
        $this->createItem('Soy Latte', [['ingredient_name' => 'soy milk', 'quantity' => 200, 'unit' => 'ml']])->assertCreated();
        $this->assertTrue($old->fresh()->is_active);
        $this->assertSame(3, Ingredient::count());
    }

    public function test_owner_adds_an_ingredient_without_duplicating_one(): void
    {
        $this->api('POST', '/api/owner/ingredients', ['name' => '  Oat  Milk ', 'unit' => 'ml'])
            ->assertCreated()
            ->assertJsonPath('ingredient.name', 'Oat Milk')
            ->assertJsonPath('ingredient.used_in', 0);

        $this->api('POST', '/api/owner/ingredients', ['name' => 'oat milk', 'unit' => 'l'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Oat Milk is already in your ingredient list.');
    }

    public function test_rename_updates_recipes_but_not_past_consumption(): void
    {
        $this->createItem('Latte', [['ingredient_name' => 'Milk', 'quantity' => 200, 'unit' => 'ml']])->assertCreated();
        Ingredient::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Espresso', 'unit' => 'shots']);
        $milk = Ingredient::where('name', 'Milk')->first();

        $this->api('PATCH', "/api/owner/ingredients/{$milk->uuid}", ['name' => 'espresso'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->api('PATCH', "/api/owner/ingredients/{$milk->uuid}", ['name' => 'Fresh Milk'])
            ->assertOk()
            ->assertJsonPath('ingredient.name', 'Fresh Milk')
            ->assertJsonPath('ingredient.used_in', 1);

        $this->assertSame('Fresh Milk', DB::table('menu_recipes')->where('ingredient_id', $milk->ingredient_id)->value('ingredient_name'));

        // Renaming only in case is fine (it's the same ingredient).
        $this->api('PATCH', "/api/owner/ingredients/{$milk->uuid}", ['name' => 'fresh milk'])->assertOk();
    }

    public function test_unit_can_only_change_while_no_recipe_uses_it(): void
    {
        $this->createItem('Latte', [['ingredient_name' => 'Milk', 'quantity' => 200, 'unit' => 'ml']])->assertCreated();
        $milk  = Ingredient::where('name', 'Milk')->first();
        $sugar = Ingredient::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Sugar', 'unit' => 'g']);

        $this->api('PATCH', "/api/owner/ingredients/{$milk->uuid}", ['unit' => 'l'])
            ->assertStatus(422)
            ->assertJsonPath('errors.unit.0', 'Used in 1 menu item, whose amounts are in ml. Remove it from those recipes before changing the unit.');

        // Sending the same unit back isn't a change.
        $this->api('PATCH', "/api/owner/ingredients/{$milk->uuid}", ['name' => 'Milk', 'unit' => 'ml'])->assertOk();

        $this->api('PATCH', "/api/owner/ingredients/{$sugar->uuid}", ['unit' => 'kg'])
            ->assertOk()->assertJsonPath('ingredient.unit', 'kg');
    }

    public function test_retire_and_restore(): void
    {
        $this->createItem('Latte', [['ingredient_name' => 'Milk', 'quantity' => 200, 'unit' => 'ml']])->assertCreated();
        $milk = Ingredient::where('name', 'Milk')->first();

        $this->api('PATCH', "/api/owner/ingredients/{$milk->uuid}", ['is_active' => false])->assertOk();

        // Hidden from the recipe picker, still on the Ingredients page, recipe untouched.
        $this->api('GET', '/api/owner/ingredients')->assertJsonCount(0, 'ingredients');
        $this->api('GET', '/api/owner/ingredients?include_retired=1')
            ->assertJsonCount(1, 'ingredients')
            ->assertJsonPath('ingredients.0.is_active', false);
        $this->assertSame(1, $milk->recipes()->count());

        $this->api('POST', '/api/owner/ingredients', ['name' => 'MILK', 'unit' => 'ml'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Milk is already in your list but retired. Restore it instead.');

        $this->api('PATCH', "/api/owner/ingredients/{$milk->uuid}", ['is_active' => true])
            ->assertOk()->assertJsonPath('message', 'Ingredient restored.');
    }

    public function test_cannot_edit_another_cafes_ingredient(): void
    {
        [, $otherCafe] = $this->makeOwnerWithCafe('other@test.local');
        $theirs = Ingredient::create(['cafe_id' => $otherCafe->cafe_id, 'name' => 'Milk', 'unit' => 'ml']);

        $this->api('PATCH', "/api/owner/ingredients/{$theirs->uuid}", ['name' => 'Mine now'])->assertNotFound();
        $this->assertSame('Milk', $theirs->fresh()->name);
    }

    public function test_migration_moves_existing_recipe_names_into_the_ingredient_list(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $latte = MenuItem::create(['cafe_id' => $this->cafe->cafe_id, 'menu_name' => 'Latte', 'base_price' => 100]);
        $mocha = MenuItem::create(['cafe_id' => $this->cafe->cafe_id, 'menu_name' => 'Mocha', 'base_price' => 120]);

        foreach ([
            [$latte, 'Milk', 150, 'ml'], [$latte, ' milk', 50, 'ml'], // same item twice → folded, 200 ml
            [$mocha, 'MILK', 180, 'ml'], [$mocha, 'Cocoa', 20, 'g'],
        ] as [$item, $name, $qty, $unit]) {
            DB::table('menu_recipes')->insert([
                'uuid' => (string) Str::uuid(), 'men_item_id' => $item->men_item_id,
                'ingredient_name' => $name, 'quantity' => $qty, 'unit' => $unit,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(['Cocoa', 'Milk'], Ingredient::orderBy('name')->pluck('name')->all());
        $this->assertSame(0, DB::table('menu_recipes')->whereNull('ingredient_id')->count());
        $this->assertSame(1, DB::table('menu_recipes')->where('men_item_id', $latte->men_item_id)->count());
        $this->assertEquals(200, DB::table('menu_recipes')->where('men_item_id', $latte->men_item_id)->value('quantity'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function createItem(string $name, array $recipes): TestResponse
    {
        return $this->api('POST', '/api/owner/menu-items', [
            'menu_name'  => $name,
            'base_price' => 120,
            'recipes'    => $recipes,
        ]);
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$this->token}")->json($method, $uri, $data);
    }

    /** @return array{0: User, 1: Cafe} */
    private function makeOwnerWithCafe(string $email): array
    {
        $owner = User::create([
            'firstname'         => 'Test',
            'lastname'          => 'Owner',
            'email'             => $email,
            'status'            => 'active',
            'email_verified_at' => now(),
            'role_id'           => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);

        $cafe = Cafe::create(['user_id' => $owner->user_id, 'cafe_name' => "Cafe {$email}"]);

        $plan = SubscriptionPlan::firstOrCreate(
            ['sub_name' => 'Pro'],
            ['price' => 0, 'max_branches' => 5, 'is_active' => true]
        );
        if ($plan->wasRecentlyCreated) {
            $plan->features()->attach(Feature::create(['key' => 'menu_management', 'name' => 'Menu', 'is_active' => true])->feature_id);
        }

        Subscription::create([
            'uuid'        => (string) Str::uuid(),
            'user_id'     => $owner->user_id,
            'sub_plan_id' => $plan->sub_plan_id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addMonth(),
            'status'      => 'active',
        ]);

        return [$owner, $cafe];
    }
}
