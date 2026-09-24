<?php

namespace Database\Seeders;

use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure the "Cafe Owner" role exists
        $role = Role::firstOrCreate(
            ['role_name' => 'Cafe Owner'],
            ['uuid' => (string) Str::uuid()]
        );

        // 2. Create the test owner — active, verified, password already set
        $owner = User::firstOrCreate(
            ['email' => 'owner@brewspot.test'],
            [
                'uuid'               => (string) Str::uuid(),
                'firstname'          => 'Juan',
                'middlename'         => null,
                'lastname'           => 'Dela Cruz',
                'username'           => 'juandelacruz',
                'password_hash'      => Hash::make('Password123!'),
                'phone_number'       => '09171234567',
                'address'            => '456 Bonifacio St, Davao City',
                'email_verified_at'  => Carbon::now(),
                'status'             => 'active',
                'role_id'            => $role->role_id,
            ]
        );

        // 3. Give them a cafe
        $cafe = Cafe::firstOrCreate(
            ['user_id' => $owner->user_id],
            [
                'uuid'      => (string) Str::uuid(),
                'cafe_name' => 'Brew Haven',
            ]
        );

        // 4. Give the cafe an active main branch
        CafeBranch::firstOrCreate(
            ['cafe_email' => 'branch@brewhaven.test'],
            [
                'uuid'             => (string) Str::uuid(),
                'cafe_id'          => $cafe->cafe_id,
                'branch_name'      => 'Brew Haven — Main Branch',
                'cafe_picture'     => null,
                'cafe_phonenumber' => '09181234567',
                'address'          => '123 Rizal St, Davao City',
                'branch_type'      => 'main',
                'status'           => 'active',
            ]
        );

        // 5. Give them an active subscription (uses/creates a Trial Plan)
        $plan = SubscriptionPlan::firstOrCreate(
            ['sub_name' => 'Trial Plan'],
            [
                'uuid'          => (string) Str::uuid(),
                'price'         => 0,
                'max_branches'  => 1,
                'duration_days' => 15,
                'description'   => 'Free 15-day trial plan.',
                'is_active'     => true,
            ]
        );

        $subscription = Subscription::firstOrCreate(
            ['user_id' => $owner->user_id, 'sub_plan_id' => $plan->sub_plan_id],
            [
                'uuid'                  => (string) Str::uuid(),
                'start_date'            => Carbon::now(),
                'end_date'              => Carbon::now()->addDays($plan->duration_days),
                'status'                => 'active',
                'billing_cycle'         => 'trial',
                'cancel_at_period_end'  => false,
            ]
        );

        $subscription->payments()->firstOrCreate(
            ['user_id' => $owner->user_id, 'payment_method_type' => 'Free Trial'],
            [
                'uuid'                => (string) Str::uuid(),
                'amount'              => 0,
                'status'              => 'succeeded',
                'gateway_transaction_id' => 'TRIAL-' . strtoupper(Str::random(7)),
            ]
        );

        // 6. Give the cafe opening hours (in case the creating event didn't run, e.g. if the cafe already existed)
        if ($cafe->openingHours()->count() === 0) {
            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            foreach ($days as $day) {
                $isWeekend = in_array($day, ['Saturday', 'Sunday']);
                $cafe->openingHours()->create([
                    'day_of_week' => $day,
                    'is_closed'   => $isWeekend,
                    'open_time'   => $isWeekend ? null : '09:00:00',
                    'close_time'  => $isWeekend ? null : '17:00:00',
                ]);
            }
        }

        // 7. Add a Menu Category
        $category = \App\Models\MenuCategory::firstOrCreate(
            ['cafe_id' => $cafe->cafe_id, 'name' => 'Signature Coffee'],
            [
                'uuid' => (string) Str::uuid(),
                'is_available' => true,
            ]
        );

        // 8. Add a Menu Item to the category
        $item = \App\Models\MenuItem::firstOrCreate(
            ['cafe_id' => $cafe->cafe_id, 'men_category_id' => $category->men_category_id, 'menu_name' => 'Caramel Macchiato'],
            [
                'uuid'         => (string) Str::uuid(),
                'description'  => 'Espresso mixed with vanilla-flavored syrup, milk, and caramel drizzle.',
                'base_price'   => 150.00,
                'is_available' => true,
            ]
        );

        // 9. Add the recipe for the menu item
        $recipe = [
            ['ingredient_name' => 'Espresso',       'quantity' => 2,   'unit' => 'shots'],
            ['ingredient_name' => 'Milk',           'quantity' => 240, 'unit' => 'ml'],
            ['ingredient_name' => 'Vanilla Syrup',  'quantity' => 15,  'unit' => 'ml'],
            ['ingredient_name' => 'Caramel Sauce',  'quantity' => 10,  'unit' => 'ml'],
        ];

        foreach ($recipe as $ingredient) {
            \App\Models\MenuRecipe::firstOrCreate(
                ['men_item_id' => $item->men_item_id, 'ingredient_name' => $ingredient['ingredient_name']],
                [
                    'uuid'     => (string) Str::uuid(),
                    'quantity' => $ingredient['quantity'],
                    'unit'     => $ingredient['unit'],
                ]
            );
        }

        $this->command->info('Test owner ready:');
        $this->command->info('  email:    owner@brewspot.test');
        $this->command->info('  password: Password123!');
    }
}
