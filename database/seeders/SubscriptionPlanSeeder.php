<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('subscription_plans')->insert([
            [
                'uuid' => Str::uuid(),
                'sub_name' => 'Trial Plan',
                'price' => 0.00,
                'max_branches' => 1,
                'description' => 'Perfect for starting cafes.',
                'duration_days' => 15, // Defined 15-day trial
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => Str::uuid(),
                'sub_name' => 'Basic Plan',
                'price' => 599.00,
                'max_branches' => 1,
                'description' => 'For growing cafe chains.',
                'duration_days' => 30, 
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => Str::uuid(),
                'sub_name' => 'Premium Plan',
                'price' => 999.00,
                'max_branches' => 3,
                'description' => 'For growing cafe chains.',
                'duration_days' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'uuid' => Str::uuid(),
                'sub_name' => 'Enterprise Plan',
                'price' => 1499.00,
                'max_branches' => 6,
                'description' => 'For growing cafe chains.',
                'duration_days' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}