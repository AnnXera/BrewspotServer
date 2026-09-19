<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $features = [
            [
                'uuid'        => (string) Str::uuid(),
                'key'         => 'menu_management',
                'name'        => 'Menu Management',
                'description' => 'Create and manage your cafe menu categories and items.',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'uuid'        => (string) Str::uuid(),
                'key'         => 'pos_system',
                'name'        => 'Point of Sale (POS) System',
                'description' => 'Process orders and transactions using the built-in POS.',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'uuid'        => (string) Str::uuid(),
                'key'         => 'staff_management',
                'name'        => 'Staff Management',
                'description' => 'Invite, manage, and assign roles to cashiers and managers for your branches.',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'uuid'        => (string) Str::uuid(),
                'key'         => 'advanced_analytics',
                'name'        => 'Advanced Analytics & Reports',
                'description' => 'Access revenue forecasts, peak hour insights, and exportable financial logs.',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'uuid'        => (string) Str::uuid(),
                'key'         => 'promotions_discounts',
                'name'        => 'Custom Promotions & Discounts',
                'description' => 'Create coupon codes, happy hour discounts, and promotional banners.',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'uuid'        => (string) Str::uuid(),
                'key'         => 'custom_branding',
                'name'        => 'Custom Cafe Branding',
                'description' => 'Upload custom logos, receipt footers, and tailored customer order screens.',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'uuid'        => (string) Str::uuid(),
                'key'         => 'inventory_tracking',
                'name'        => 'Real-Time Inventory Tracking',
                'description' => 'Track ingredient stock levels, low-stock warnings, and wastage reports.',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'uuid'        => (string) Str::uuid(),
                'key'         => 'multi_branch',
                'name'        => 'Multi-Branch Management',
                'description' => 'Create and manage additional branch locations beyond your first branch.',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ];

        foreach ($features as $feature) {
            DB::table('features')->updateOrInsert(
                ['key' => $feature['key']],
                $feature
            );
        }
    }
}
