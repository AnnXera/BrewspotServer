<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
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
                'yearly_price'  => 0.00,
                'max_branches'  => 1,
                'features'      => [
                    'staff_management',
                    'advanced_analytics',
                    'promotions_discounts',
                    'custom_branding',
                    'inventory_tracking',
                ],
                'description'   => 'Full-feature access trial for new cafes.',
                'duration_days' => 15,
                'is_active'     => true,
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Basic Plan',
                'price'         => 599.00,
                'yearly_price'  => 5990.00,
                'max_branches'  => 1,
                'features'      => [
                    'staff_management',
                ],
                'description'   => 'For single-branch cafes needing staff accounts.',
                'duration_days' => 30,
                'is_active'     => true,
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Premium Plan',
                'price'         => 999.00,
                'yearly_price'  => 9990.00,
                'max_branches'  => 3,
                'features'      => [
                    'staff_management',
                    'advanced_analytics',
                    'promotions_discounts',
                ],
                'description'   => 'For growing cafes looking to expand and optimize sales.',
                'duration_days' => 30,
                'is_active'     => true,
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Enterprise Plan',
                'price'         => 1499.00,
                'yearly_price'  => 14990.00,
                'max_branches'  => 6,
                'features'      => [
                    'staff_management',
                    'advanced_analytics',
                    'promotions_discounts',
                    'custom_branding',
                    'inventory_tracking',
                ],
                'description'   => 'Complete powerhouse solution with multi-branch management & full features.',
                'duration_days' => 30,
                'is_active'     => true,
            ],
        ];

        foreach ($plans as $planData) {
            $featureKeys = $planData['features'] ?? [];
            unset($planData['features']);

            $plan = SubscriptionPlan::updateOrCreate(
                ['sub_name' => $planData['sub_name']],
                $planData
            );

            if (! empty($featureKeys)) {
                $featureIds = Feature::whereIn('key', $featureKeys)->pluck('feature_id');
                $plan->features()->sync($featureIds);
            }
        }
    }
}