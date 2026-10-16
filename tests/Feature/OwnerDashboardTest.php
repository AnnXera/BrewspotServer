<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner dashboard: totals and income across all branches
 */
class OwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cafe $cafe;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-16 15:00:00');

        Role::create(['role_name' => 'Admin']);
        Role::create(['role_name' => 'Cafe Owner']);

        $this->owner = $this->makeOwner('owner@test.local');
        $this->cafe  = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Test Cafe']);
        $this->token = $this->owner->createToken('test')->plainTextToken;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_combines_every_branch_and_buckets_by_range(): void
    {
        $main = $this->makeBranch($this->cafe, 'Main', 'active');
        $side = $this->makeBranch($this->cafe, 'Side', 'active');
        $this->makeBranch($this->cafe, 'New', 'pending_approval');

        $drinks = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Drinks', 'is_available' => true]);
        $food   = MenuCategory::create(['cafe_id' => $this->cafe->cafe_id, 'name' => 'Food', 'is_available' => true]);
        $latte  = $this->makeItem('Latte', $drinks, 120);
        $bagel  = $this->makeItem('Bagel', $food, 80);

        $this->sale($main, '2026-10-16 09:00', [[$latte, 3]]);           // today
        $this->sale($side, '2026-10-15 10:00', [[$latte, 1], [$bagel, 2]]); // yesterday
        $this->sale($side, '2026-10-16 11:00', [[$bagel, 1]], 'voided'); // ignored
        $this->sale($main, '2026-10-08 10:00', [[$bagel, 1]]);           // last week, before Friday
        // Two cheap bagel-only orders: Food is in more orders, Drinks still earns more.
        $this->sale($main, '2026-10-15 12:00', [[$bagel, 1]]);
        $this->sale($main, '2026-10-15 13:00', [[$bagel, 1]]);

        $this->api('GET', '/api/owner/dashboard-stats?range=daily')
            ->assertOk()
            ->assertJsonPath('totals.branches', 3)
            ->assertJsonPath('totals.active_branches', 2)
            ->assertJsonPath('totals.pending_branches', 1)
            ->assertJsonPath('totals.orders', 5)
            ->assertJsonPath('period.from', '2026-10-11') // Sunday
            ->assertJsonPath('period.to', '2026-10-17')   // Saturday
            ->assertJsonPath('totals.today_orders', 1)
            ->assertJsonCount(7, 'series')
            ->assertJsonPath('series.0.label', 'Sun')
            ->assertJsonPath('series.5.start', '2026-10-16')
            ->assertJsonPath('series.5.revenue', 360)
            ->assertJsonPath('series.4.revenue', 440)
            ->assertJsonPath('series.6.upcoming', true)
            ->assertJsonPath('series.5.upcoming', false)
            ->assertJsonPath('period.orders', 4)
            ->assertJsonPath('period.previous_revenue', 80)
            ->assertJsonPath('trending_items.0.name', 'Bagel')
            ->assertJsonPath('trending_items.0.orders', 3)
            ->assertJsonPath('trending_items.0.share', 75)
            ->assertJsonPath('trending_items.1.name', 'Latte')
            ->assertJsonPath('trending_items.1.quantity', 4)
            ->assertJsonPath('top_categories.0.name', 'Food')
            ->assertJsonPath('top_categories.0.orders', 3)
            ->assertJsonPath('top_categories.0.share', 75)
            ->assertJsonPath('top_categories.1.name', 'Drinks')
            ->assertJsonPath('top_categories.1.orders', 2)
            ->assertJsonPath('branches.0.name', 'Main');

        $this->api('GET', '/api/owner/dashboard-stats?range=monthly')
            ->assertOk()
            ->assertJsonCount(12, 'series')
            ->assertJsonPath('series.11.start', '2026-10-01')
            ->assertJsonPath('series.11.orders', 5)
            ->assertJsonPath('series.10.orders', 0);

        $this->api('GET', '/api/owner/dashboard-stats?range=weekly')
            ->assertOk()
            ->assertJsonPath('series.11.start', '2026-10-11')
            ->assertJsonPath('series.11.end', '2026-10-17');
    }

    public function test_ignores_other_owners_sales(): void
    {
        $stranger = $this->makeOwner('stranger@test.local');
        $their    = Cafe::create(['user_id' => $stranger->user_id, 'cafe_name' => 'Other Cafe']);
        $branch   = $this->makeBranch($their, 'Theirs', 'active');
        $this->sale($branch, '2026-10-16 09:00', [[$this->makeItem('Mocha', null, 100, $their), 2]]);

        $this->api('GET', '/api/owner/dashboard-stats')
            ->assertOk()
            ->assertJsonPath('totals.orders', 0)
            ->assertJsonPath('period.revenue', 0)
            ->assertJsonCount(0, 'trending_items');
    }

    public function test_rejects_unknown_range(): void
    {
        $this->api('GET', '/api/owner/dashboard-stats?range=hourly')->assertStatus(422);
    }

    private function makeOwner(string $email): User
    {
        return User::create([
            'firstname'         => 'Test',
            'lastname'          => 'Owner',
            'email'             => $email,
            'status'            => 'active',
            'email_verified_at' => now(),
            'role_id'           => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);
    }

    private function makeBranch(Cafe $cafe, string $name, string $status): CafeBranch
    {
        return CafeBranch::create([
            'cafe_id'          => $cafe->cafe_id,
            'branch_name'      => $name,
            'cafe_email'       => Str::slug($name) . '-' . Str::random(4) . '@test.local',
            'cafe_phonenumber' => '+639170000000',
            'address'          => 'Davao City',
            'branch_type'      => 'main',
            'status'           => $status,
        ]);
    }

    private function makeItem(string $name, ?MenuCategory $category, float $price, ?Cafe $cafe = null): MenuItem
    {
        return MenuItem::create([
            'cafe_id'         => ($cafe ?? $this->cafe)->cafe_id,
            'men_category_id' => $category?->men_category_id,
            'menu_name'       => $name,
            'base_price'      => $price,
            'is_available'    => true,
        ]);
    }

    /**
     * @param array<int, array{0: MenuItem, 1: int}> $lines
     */
    private function sale(CafeBranch $branch, string $at, array $lines, string $status = 'completed'): void
    {
        $total = collect($lines)->sum(fn ($l) => $l[0]->base_price * $l[1]);

        $tx = Transaction::create(['branch_id' => $branch->branch_id, 'total_amount' => $total, 'status' => $status]);
        $tx->forceFill(['created_at' => Carbon::parse($at)])->save();

        foreach ($lines as [$item, $qty]) {
            TransactionItem::create([
                'transaction_id' => $tx->transaction_id,
                'men_item_id'    => $item->men_item_id,
                'quantity'       => $qty,
                'unit_price'     => $item->base_price,
            ]);
        }
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$this->token}")->json($method, $uri, $data);
    }
}
