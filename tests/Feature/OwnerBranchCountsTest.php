<?php

namespace Tests\Feature;

use App\Models\CafeBranch;
use Illuminate\Support\Str;

/**
 * The summary cards on Cafe Management count all of the owner's branches, not the one page
 * (or filtered slice) the branch list is showing.
 */
class OwnerBranchCountsTest extends FloorPlanTestCase
{
    private function branch(string $name, string $status): CafeBranch
    {
        return CafeBranch::create([
            'cafe_id'          => $this->cafe->cafe_id,
            'branch_name'      => $name,
            'cafe_email'       => Str::slug($name) . '-' . Str::random(4) . '@test.local',
            'cafe_phonenumber' => '+639170000000',
            'address'          => 'Davao City',
            'branch_type'      => 'side',
            'status'           => $status,
        ]);
    }

    public function test_branch_counts_cover_every_branch_whatever_the_page_or_filter(): void
    {
        $this->otherBranch->update(['status' => 'inactive']);
        $this->branch('Waiting Branch', 'pending_approval');
        $this->branch('Turned Down Branch', 'rejected');

        // One branch per page, filtered to active: the list shows one row, the counts all four.
        $this->api('GET', '/api/owner/branches?per_page=1&status=active', $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonCount(1, 'branches.data')
            ->assertJsonPath('branch_counts.total', 4)
            ->assertJsonPath('branch_counts.active', 1)
            ->assertJsonPath('branch_counts.inactive', 1)
            ->assertJsonPath('branch_counts.pending', 1)
            ->assertJsonPath('branch_counts.rejected', 1);
    }

    public function test_search_finds_a_branch_by_its_managers_name(): void
    {
        // The fixture's manager runs the main branch only.
        $first = $this->manager->firstname;
        $full  = "{$this->manager->firstname} {$this->manager->lastname}";

        foreach ([$first, $full] as $term) {
            $this->api('GET', '/api/owner/branches?search=' . urlencode($term), $this->tokenFor($this->owner))
                ->assertOk()
                ->assertJsonCount(1, 'branches.data')
                ->assertJsonPath('branches.data.0.uuid', $this->mainBranch->uuid);
        }
    }
}
