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
                    'menu_management',
                    'pos_system',
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
                'paypal_plan_id'        => 'P-7VA03541JG019960ANKXFADI', 
                'paypal_yearly_plan_id' => 'P-7M88997484814054BNKXFADQ', 
                'features'      => [
                    'menu_management',
                    'pos_system',
                    'advanced_analytics',
                    'staff_management',
                ],
                'description'   => 'For single-branch cafes needing staff accounts.',
                'duration_days' => null,
                'is_active'     => true,
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Premium Plan',
                'price'         => 999.00,
                'yearly_price'  => 9990.00,
                'max_branches'  => 1,
                'paypal_plan_id'        => 'P-00263482DL842702XNKXFADY', 
                'paypal_yearly_plan_id' => 'P-5BH12763P6071835BNKXFAEA', 
                'features'      => [
                    'staff_management',
                    'advanced_analytics',
                    'promotions_discounts',
                    'multi_branch',
                ],
                'description'   => 'For growing cafes looking to expand and optimize sales.',
                'duration_days' => null,
                'is_active'     => true,
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Enterprise Plan',
                'price'         => 1499.00,
                'yearly_price'  => 14990.00,
                'max_branches'  => 1,
                'paypal_plan_id'        => 'P-6HL576380D819424GNKXFAEI', 
                'paypal_yearly_plan_id' => 'P-2JU728374D610094ANKXFAEQ', 
                'features'      => [
                    'staff_management',
                    'advanced_analytics',
                    'promotions_discounts',
                    'custom_branding',
                    'inventory_tracking',
                    'multi_branch',
                ],
                'description'   => 'Complete powerhouse solution with multi-branch management & full features.',
                'duration_days' => null,
                'is_active'     => true,
            ],
            [
                'uuid'          => (string) Str::uuid(),
                'sub_name'      => 'Daily Test Plan',
                'price'         => 10.00,
                'yearly_price'  => 0, // No yearly for the test plan
                'max_branches'  => 1,
                'paypal_plan_id'        => 'P-4FC70833YM0407406NKXVLKA', // Leave empty so sync-plans generates it
                'paypal_yearly_plan_id' => '',
                'features'      => [
                    'menu_management',
                ],
                'description'   => 'Temporary plan for testing daily billing cycles.',
                'duration_days' => null,
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