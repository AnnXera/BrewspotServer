<?php

namespace Tests\Feature;

use App\Models\BranchTable;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\Feature;
use App\Models\FloorPlan;
use App\Models\InventoryServing;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\ServingSpoilageLog;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionItemAddon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner Analytics page: sales, menu, branches and operations
 */
class OwnerAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cafe $cafe;
    private CafeBranch $main;
    private CafeBranch $side;
    private MenuItem $latte;
    private MenuItem $bagel;
    private MenuItem $tea;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-16 15:00:00'); // Friday

        Role::create(['role_name' => 'Admin']);
        Role::create(['role_name' => 'Cafe Owner']);
        Role::create(['role_name' => 'Cashier']);

        $this->owner = $this->makeUser('owner@test.local', 'Cafe Owner');
        $this->cafe  = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Test Cafe']);
        $this->token = $this->owner->createToken('test')->plainTextToken;
        $this->subscribe($this->owner, ['advanced_analytics']);

        $this->main = $this->makeBranch($this->cafe, 'Main');
        $this->side = $this->makeBranch($this->cafe, 'Side');

        $drinks      = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Drinks', 'is_available' => true]);
        $food        = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Food', 'is_available' => true]);
        $this->latte = $this->makeItem('Latte', $drinks, 120);
        $this->bagel = $this->makeItem('Bagel', $food, 80);
        $this->tea   = $this->makeItem('Tea', $drinks, 90); // never sold
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sales_summary_compares_with_the_window_before(): void
    {
        $this->sale($this->main, '2026-10-16 09:15', [[$this->latte, 2]], 'cash');                     // Fri 9am
        $this->sale($this->side, '2026-10-15 09:40', [[$this->bagel, 1]], 'gcash', discount: 16);       // Thu 9am
        $this->sale($this->main, '2026-10-14 14:05', [[$this->latte, 1], [$this->bagel, 1]], 'cash');   // Wed 2pm
        $this->sale($this->main, '2026-10-16 10:00', [[$this->latte, 1]], 'cash', status: 'voided');
        $this->sale($this->main, '2026-10-08 10:00', [[$this->bagel, 2]], 'cash');                     // previous week

        $this->api('/api/owner/analytics/sales?from=2026-10-10&to=2026-10-16')
            ->assertOk()
            ->assertJsonPath('filters.days', 7)
            ->assertJsonPath('filters.previous_from', '2026-10-03')
            ->assertJsonPath('filters.previous_to', '2026-10-09')
            ->assertJsonPath('summary.orders', 3)
            ->assertJsonPath('summary.revenue', 504)          // 240 + 64 + 200
            ->assertJsonPath('summary.items_sold', 5)
            ->assertJsonPath('summary.discounts', 16)
            ->assertJsonPath('summary.discounted_orders', 1)
            ->assertJsonPath('summary.voided_orders', 1)
            ->assertJsonPath('summary.previous.revenue', 160)
            ->assertJsonPath('summary.change.revenue', 215)
            ->assertJsonPath('granularity', 'day')
            ->assertJsonCount(7, 'series')
            ->assertJsonPath('series.6.revenue', 240)
            ->assertJsonPath('heatmap.5.9.orders', 1)         // Friday, 9am
            ->assertJsonPath('heatmap.4.9.orders', 1)         // Thursday, 9am
            ->assertJsonPath('heatmap.3.14.revenue', 200)     // Wednesday, 2pm
            ->assertJsonPath('payments.0.method', 'cash')
            ->assertJsonPath('payments.0.orders', 2)
            ->assertJsonPath('discounts.0.amount', 16);

        // One branch only
        $this->api("/api/owner/analytics/sales?from=2026-10-10&to=2026-10-16&branch={$this->side->uuid}")
            ->assertOk()
            ->assertJsonPath('filters.branch.name', 'Side')
            ->assertJsonPath('summary.orders', 1);
    }

    public function test_long_windows_roll_up_into_weeks_and_months(): void
    {
        $this->api('/api/owner/analytics/sales?from=2026-08-01&to=2026-10-16')
            ->assertOk()
            ->assertJsonPath('granularity', 'week')
            ->assertJsonPath('series.0.start', '2026-08-01')
            ->assertJsonPath('series.0.end', '2026-08-01')    // Saturday: first week is clipped
            ->assertJsonPath('series.1.start', '2026-08-02');

        $this->api('/api/owner/analytics/sales?from=2025-11-01&to=2026-10-16')
            ->assertOk()
            ->assertJsonPath('granularity', 'month')
            ->assertJsonCount(12, 'series')
            ->assertJsonPath('series.11.end', '2026-10-16');
    }

    public function test_menu_lists_items_unsold_items_and_add_ons(): void
    {
        $line = $this->sale($this->main, '2026-10-15 09:00', [[$this->latte, 2, 'Large']], 'cash');
        TransactionItemAddon::create(['transaction_item_id' => $line[0]->transaction_item_id, 'addon_name' => 'Extra shot', 'price' => 30, 'quantity' => 1]);
        $this->sale($this->main, '2026-10-15 10:00', [[$this->bagel, 1]], 'cash');
        $this->sale($this->main, '2026-10-15 11:00', [[$this->bagel, 1]], 'cash');

        $this->api('/api/owner/analytics/menu?from=2026-10-10&to=2026-10-16')
            ->assertOk()
            ->assertJsonPath('items.0.name', 'Latte')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.1.name', 'Bagel')
            ->assertJsonPath('items.1.orders', 2)
            ->assertJsonCount(1, 'not_selling')
            ->assertJsonPath('not_selling.0.name', 'Tea')
            ->assertJsonPath('categories.0.name', 'Food')       // in more orders
            ->assertJsonPath('sizes.0.name', 'Large')
            ->assertJsonPath('addons.0.name', 'Extra shot')
            ->assertJsonPath('addons.0.quantity', 2)
            ->assertJsonPath('addons.0.attach_rate', 33.3);
    }

    public function test_branches_are_compared_side_by_side(): void
    {
        $this->sale($this->main, '2026-10-15 09:00', [[$this->latte, 3]], 'cash');
        $this->sale($this->side, '2026-10-15 09:00', [[$this->bagel, 1]], 'cash');
        $this->sale($this->side, '2026-10-05 09:00', [[$this->bagel, 1]], 'cash');

        // The branch filter does not narrow this tab.
        $this->api("/api/owner/analytics/branches?from=2026-10-10&to=2026-10-16&branch={$this->side->uuid}")
            ->assertOk()
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('branches.0.name', 'Main')
            ->assertJsonPath('branches.0.share', 81.8)
            ->assertJsonPath('branches.0.change', null)
            ->assertJsonPath('branches.1.change', 0);
    }

    public function test_operations_cover_staff_waste_and_reservations(): void
    {
        $cashier = $this->makeUser('cashier@test.local', 'Cashier', 'Ana', 'Cruz');
        $staff   = CafeStaff::create(['user_id' => $cashier->user_id, 'branch_id' => $this->main->branch_id, 'position' => 'Cashier', 'employment_status' => 'active']);

        $this->sale($this->main, '2026-10-15 09:00', [[$this->latte, 1]], 'cash', staff: $staff);
        $this->sale($this->main, '2026-10-15 10:00', [[$this->latte, 1]], 'cash', staff: $staff);
        $this->sale($this->main, '2026-10-15 11:00', [[$this->bagel, 1]], 'cash');

        $latteDay = InventoryServing::create(['uuid' => (string) Str::uuid(), 'men_item_id' => $this->latte->men_item_id, 'branch_id' => $this->main->branch_id, 'date' => '2026-10-15', 'expected_servings' => 20, 'servings_sold' => 15, 'spoilage_qty' => 3, 'is_sold_out' => false]);
        // 2 Large logged as dropped (kept at 150), 1 recorded without an entry (base price 120);
        // the voided entry doesn't count.
        ServingSpoilageLog::create(['servings_id' => $latteDay->servings_id, 'size_name' => 'Large', 'quantity' => 2, 'unit_value' => 150, 'reason' => 'dropped']);
        ServingSpoilageLog::create(['servings_id' => $latteDay->servings_id, 'quantity' => 4, 'reason' => 'expired', 'voided_at' => '2026-10-15 12:00']);
        // Previous period (Oct 3 to 9): 2 spoiled.
        InventoryServing::create(['men_item_id' => $this->latte->men_item_id, 'branch_id' => $this->main->branch_id, 'date' => '2026-10-08', 'expected_servings' => 10, 'spoilage_qty' => 2]);
        InventoryServing::create(['uuid' => (string) Str::uuid(), 'men_item_id' => $this->bagel->men_item_id, 'branch_id' => $this->main->branch_id, 'date' => '2026-10-16', 'expected_servings' => 10, 'servings_sold' => 10, 'spoilage_qty' => 0, 'is_sold_out' => true]);

        $plan  = FloorPlan::create(['branch_id' => $this->main->branch_id, 'floorplan_name' => 'Floor', 'canvas_width' => 800, 'canvas_height' => 600, 'is_active' => true]);
        $table = BranchTable::create(['floorplan_id' => $plan->floor_plan_id, 'table_name' => 'T1', 'capacity' => 4, 'x_location' => 0, 'y_location' => 0]);
        foreach ([['completed', 4], ['completed', 2], ['no_show', 3], ['cancelled', 2]] as [$status, $party]) {
            Reservation::create([
                'table_id' => $table->table_id, 'created_by' => $this->owner->user_id, 'customer_name' => 'Guest',
                'customer_phone' => '+639170000001', 'party_size' => $party, 'reservation_date' => '2026-10-14 18:00', 'reservation_end' => '2026-10-14 19:30',
                'status' => $status,
            ]);
        }

        $this->api('/api/owner/analytics/operations?from=2026-10-10&to=2026-10-16')
            ->assertOk()
            ->assertJsonPath('staff.0.name', 'Ana Cruz')
            ->assertJsonPath('staff.0.orders', 2)
            ->assertJsonPath('staff.0.revenue', 240)
            ->assertJsonPath('staff.1.name', 'No staff recorded')
            ->assertJsonPath('waste.summary.expected', 30)
            ->assertJsonPath('waste.summary.sell_through', 83.3)
            ->assertJsonPath('waste.summary.spoilage', 3)
            ->assertJsonPath('waste.summary.spoilage_value', 420)
            ->assertJsonPath('waste.summary.spoilage_change', 50)
            ->assertJsonPath('waste.summary.sold_out_days', 1)
            ->assertJsonPath('waste.reasons', [
                ['reason' => 'dropped', 'quantity' => 2, 'share' => 66.7],
                ['reason' => 'unspecified', 'quantity' => 1, 'share' => 33.3],
            ])
            ->assertJsonPath('waste.voided', ['entries' => 1, 'quantity' => 4])
            ->assertJsonPath('waste.items.0.name', 'Latte')
            ->assertJsonPath('waste.items.0.spoilage_value', 420)
            ->assertJsonPath('reservations.total', 4)
            ->assertJsonPath('reservations.guests', 6)
            ->assertJsonPath('reservations.no_show_rate', 33.3)
            ->assertJsonPath('reservations.cancel_rate', 25)
            ->assertJsonPath('reservations.by_weekday.3.count', 4);
    }

    public function test_waste_skips_idle_days_of_deleted_items_and_flags_the_rest(): void
    {
        $idle = $this->makeItem('Espresso Sunrise', null, 150); // planned, then deleted before any sale
        $sold = $this->makeItem('Scone', null, 70);             // sold, then deleted

        InventoryServing::create(['men_item_id' => $idle->men_item_id, 'branch_id' => $this->main->branch_id, 'date' => '2026-10-15', 'expected_servings' => 10]);
        InventoryServing::create(['men_item_id' => $sold->men_item_id, 'branch_id' => $this->main->branch_id, 'date' => '2026-10-15', 'expected_servings' => 8, 'servings_sold' => 6, 'spoilage_qty' => 1]);
        InventoryServing::create(['men_item_id' => $sold->men_item_id, 'branch_id' => $this->main->branch_id, 'date' => '2026-10-16', 'expected_servings' => 8]);
        InventoryServing::create(['men_item_id' => $this->latte->men_item_id, 'branch_id' => $this->main->branch_id, 'date' => '2026-10-16', 'expected_servings' => 5]);
        $idle->delete();
        $sold->delete();

        $this->api('/api/owner/analytics/operations?from=2026-10-10&to=2026-10-16')
            ->assertOk()
            ->assertJsonCount(2, 'waste.items')
            ->assertJsonPath('waste.items.0.name', 'Scone')
            ->assertJsonPath('waste.items.0.removed', true)
            ->assertJsonPath('waste.items.0.expected', 8) // its idle day after deletion is left out
            ->assertJsonPath('waste.items.1.name', 'Latte')
            ->assertJsonPath('waste.items.1.removed', false)
            ->assertJsonPath('waste.summary.expected', 13);
    }

    public function test_rejects_bad_filters_and_other_owners_branches(): void
    {
        $stranger = $this->makeUser('stranger@test.local', 'Cafe Owner');
        $theirs   = $this->makeBranch(Cafe::create(['user_id' => $stranger->user_id, 'cafe_name' => 'Other']), 'Theirs');

        $this->api("/api/owner/analytics/sales?branch={$theirs->uuid}")->assertNotFound();
        $this->api('/api/owner/analytics/sales?from=2026-10-16&to=2026-10-01')->assertStatus(422);
        $this->api('/api/owner/analytics/sales?from=2024-01-01&to=2026-10-16')->assertStatus(422);
        $this->api('/api/owner/analytics/sales?from=2026-10-01&to=2026-12-01')->assertStatus(422);
        $this->api('/api/owner/analytics/sales')->assertOk()->assertJsonPath('filters.days', 30);
    }

    public function test_requires_the_analytics_feature(): void
    {
        Subscription::query()->delete();
        $this->subscribe($this->owner, ['menu_management']);

        $this->api('/api/owner/analytics/sales')->assertForbidden();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function makeUser(string $email, string $role, string $first = 'Test', string $last = 'User'): User
    {
        return User::create([
            'firstname'         => $first,
            'lastname'          => $last,
            'email'             => $email,
            'status'            => 'active',
            'email_verified_at' => now(),
            'role_id'           => Role::where('role_name', $role)->value('role_id'),
        ]);
    }

    private function subscribe(User $owner, array $features): void
    {
        $plan = SubscriptionPlan::create(['sub_name' => 'Plan ' . Str::random(4), 'price' => 0, 'max_branches' => 5, 'is_active' => true]);
        foreach ($features as $key) {
            $feature = Feature::firstOrCreate(['key' => $key], ['name' => $key, 'is_active' => true]);
            $plan->features()->attach($feature->feature_id);
        }
        Subscription::create([
            'uuid'        => (string) Str::uuid(),
            'user_id'     => $owner->user_id,
            'sub_plan_id' => $plan->sub_plan_id,
            'start_date'  => now()->subDay(),
            'end_date'    => now()->addMonth(),
            'status'      => 'active',
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

    private function makeItem(string $name, ?MenuCategory $category, float $price): MenuItem
    {
        return MenuItem::create([
            'cafe_id'         => $this->cafe->cafe_id,
            'men_category_id' => $category?->men_category_id,
            'menu_name'       => $name,
            'base_price'      => $price,
            'is_available'    => true,
        ]);
    }

    /**
     * @param array<int, array{0: MenuItem, 1: int, 2?: string}> $lines item, quantity, size name
     * @return TransactionItem[]
     */
    private function sale(CafeBranch $branch, string $at, array $lines, string $method, float $discount = 0, string $status = 'completed', ?CafeStaff $staff = null): array
    {
        $total = collect($lines)->sum(fn ($l) => $l[0]->base_price * $l[1]) - $discount;

        $tx = Transaction::create([
            'branch_id'       => $branch->branch_id,
            'staff_id'        => $staff?->staff_id,
            'total_amount'    => $total,
            'discount_type'   => $discount > 0 ? 'senior' : null,
            'discount_amount' => $discount,
            'status'          => $status,
        ]);
        $tx->forceFill(['created_at' => Carbon::parse($at)])->save();

        Payment::create([
            'user_id' => $this->owner->user_id, 'payable_type' => Transaction::class, 'payable_id' => $tx->transaction_id,
            'payment_method_type' => $method, 'amount' => $total, 'amount_tendered' => $total, 'amount_change' => 0, 'status' => 'paid',
        ]);

        return array_map(fn ($l) => TransactionItem::create([
            'transaction_id' => $tx->transaction_id,
            'men_item_id'    => $l[0]->men_item_id,
            'size_name'      => $l[2] ?? null,
            'quantity'       => $l[1],
            'unit_price'     => $l[0]->base_price,
        ]), $lines);
    }

    private function api(string $uri): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$this->token}")->getJson($uri);
    }
}
