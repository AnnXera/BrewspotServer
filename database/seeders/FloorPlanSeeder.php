<?php

namespace Database\Seeders;

use App\Models\CafeBranch;
use App\Models\FloorPlan;
use Illuminate\Database\Seeder;

/**
 * A starter "Main Floor" for every active branch that has no floor plan yet.
 * Safe to re-run: branches that already have a plan are left alone.
 */
class FloorPlanSeeder extends Seeder
{
    public function run(): void
    {
        CafeBranch::where('status', 'active')->each(function (CafeBranch $branch) {
            if (FloorPlan::where('branch_id', $branch->branch_id)->exists()) {
                return;
            }

            $plan = FloorPlan::create([
                'branch_id'      => $branch->branch_id,
                'floorplan_name' => 'Main Floor',
                'canvas_width'   => 1000,
                'canvas_height'  => 700,
                'is_active'      => true,
            ]);

            $tables = [
                ['T1', 'table_round_2', 2, 150, 200],
                ['T2', 'table_round_2', 2, 150, 400],
                ['T3', 'table_square_4', 4, 400, 200],
                ['T4', 'table_square_4', 4, 400, 400],
                ['T5', 'table_rect_6', 6, 700, 250],
                ['T6', 'table_rect_8', 8, 700, 500],
            ];

            foreach ($tables as [$name, $asset, $capacity, $x, $y]) {
                $plan->tables()->create([
                    'table_name' => $name,
                    'capacity'   => $capacity,
                    'asset_key'  => $asset,
                    'x_location' => $x,
                    'y_location' => $y,
                    'rotation'   => 0,
                    'status'     => 'available',
                ]);
            }

            foreach ([
                ['counter', 'counter_pos', 'Counter', 850, 80, 1],
                ['door', 'door_double', 'Entrance', 500, 680, 1],
                ['plant', 'plant_large', null, 40, 60, 2],
                ['plant', 'plant_small', null, 950, 650, 2],
            ] as [$category, $asset, $label, $x, $y, $z]) {
                $plan->elements()->create([
                    'category'   => $category,
                    'asset_key'  => $asset,
                    'label'      => $label,
                    'x_location' => $x,
                    'y_location' => $y,
                    'rotation'   => 0,
                    'z_index'    => $z,
                ]);
            }
        });
    }
}
