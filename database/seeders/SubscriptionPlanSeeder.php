<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Trial Plan',
                'price'         => 0.00,
                'max_branches'  => 1,
                'features'      => json_encode([]),
                'description'   => 'Perfect for starting cafes.',
                'duration_days' => 15,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Basic Plan',
                'price'         => 599.00,
                'max_branches'  => 1,
                'features'      => json_encode([
                    'staff_management',
                ]),
                'description'   => 'For single-branch cafes needing staff accounts.',
                'duration_days' => 30,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Premium Plan',
                'price'         => 999.00,
                'max_branches'  => 3,
                'features'      => json_encode([
                    'staff_management',
                    'advanced_analytics',
                    'promotions_discounts',
                ]),
                'description'   => 'For growing cafes looking to expand and optimize sales.',
                'duration_days' => 30,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Enterprise Plan',
                'price'         => 1499.00,
                'max_branches'  => 6,
                'features'      => json_encode([
                    'staff_management',
                    'advanced_analytics',
                    'promotions_discounts',
                    'custom_branding',
                    'inventory_tracking',
                ]),
                'description'   => 'Complete powerhouse solution with multi-branch management & full features.',
                'duration_days' => 30,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('subscription_plans')->updateOrInsert(
                ['sub_name' => $plan['sub_name']],
                $plan
            );
        }
    }
}