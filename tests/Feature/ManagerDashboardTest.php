<?php

namespace Tests\Feature;

use App\Models\CafeBranch;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * Manager dashboard stats: peak hours heatmap for the manager's branch.
 */
class ManagerDashboardTest extends FloorPlanTestCase
{
    public function test_peak_hours_cover_the_last_four_weeks_of_the_branch(): void
    {
        // Now: Monday 2026-10-05 08:00
        $this->sale($this->mainBranch, '2026-10-04 09:30:00', 150);              // Sunday 9am
        $this->sale($this->mainBranch, '2026-10-03 14:10:00', 80, 'paid');       // Saturday 2pm, paid counts
        $this->sale($this->mainBranch, '2026-10-05 07:30:00', 60);               // Monday 7am, today
        $this->sale($this->mainBranch, '2026-10-04 09:45:00', 999, 'void');      // voided, ignored
        $this->sale($this->mainBranch, '2026-08-01 14:00:00', 999);              // Saturday 2pm, too old
        $this->sale($this->otherBranch, '2026-10-04 09:00:00', 999);             // another branch

        $this->api('GET', $this->base('manager') . '/dashboard-stats', $this->tokenFor($this->manager))
            ->assertOk()
            ->assertJsonPath('peak_hours.from', '2026-09-08')
            ->assertJsonPath('peak_hours.to', '2026-10-05')
            ->assertJsonCount(7, 'peak_hours.grid')
            ->assertJsonCount(24, 'peak_hours.grid.0')
            ->assertJsonPath('peak_hours.grid.0.9.orders', 1)
            ->assertJsonPath('peak_hours.grid.0.9.revenue', 150)
            ->assertJsonPath('peak_hours.grid.6.14.orders', 1)
            ->assertJsonPath('peak_hours.grid.6.14.revenue', 80)
            ->assertJsonPath('peak_hours.grid.1.7.orders', 1)
            ->assertJsonPath('peak_hours.grid.3.12.orders', 0);
    }

    public function test_manager_cannot_read_another_branch(): void
    {
        $this->api('GET', $this->base('manager', $this->otherBranch) . '/dashboard-stats', $this->tokenFor($this->manager))
            ->assertForbidden();
    }

    private function sale(CafeBranch $branch, string $at, float $total, string $status = 'completed'): void
    {
        $tx = Transaction::create([
            'branch_id'    => $branch->branch_id,
            'total_amount' => $total,
            'status'       => $status,
        ]);
        $tx->forceFill(['created_at' => Carbon::parse($at)])->save();
    }
}
