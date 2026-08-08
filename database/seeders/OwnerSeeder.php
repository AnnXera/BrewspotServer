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
                'cafe_phonenumber' => '09171234567',
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

        Subscription::firstOrCreate(
            ['user_id' => $owner->user_id, 'sub_plan_id' => $plan->sub_plan_id],
            [
                'uuid'                  => (string) Str::uuid(),
                'start_date'            => Carbon::now(),
                'end_date'              => Carbon::now()->addDays($plan->duration_days),
                'status'                => 'active',
                'cancel_at_period_end'  => false,
            ]
        );

        $this->command->info('Test owner ready:');
        $this->command->info('  email:    owner@brewspot.test');
        $this->command->info('  password: Password123!');
    }
}
