<?php

namespace Tests\Feature;

use App\Models\ApprovalList;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin approving or rejecting a side branch an active owner submitted.
 */
class BranchApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $owner;
    private CafeBranch $branch;
    private ApprovalList $approval;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }

        $this->admin = User::create([
            'firstname' => 'Ada', 'lastname' => 'Admin', 'email' => 'admin@test.local',
            'status' => 'active', 'role_id' => Role::where('role_name', 'Admin')->value('role_id'),
        ]);
        $this->owner = User::create([
            'firstname' => 'Ana', 'lastname' => 'Cruz', 'email' => 'owner@test.local',
            'status' => 'active', 'role_id' => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);

        $cafe = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Bean There']);
        CafeBranch::create([
            'cafe_id' => $cafe->cafe_id, 'branch_name' => 'Main', 'cafe_email' => 'main@test.local',
            'cafe_phonenumber' => '+639170000001', 'address' => 'Matina', 'branch_type' => 'main', 'status' => 'active',
        ]);
        $this->branch = CafeBranch::create([
            'cafe_id' => $cafe->cafe_id, 'branch_name' => 'Lanang', 'cafe_email' => 'lanang@test.local',
            'cafe_phonenumber' => '+639170000002', 'address' => 'Lanang', 'branch_type' => 'side', 'status' => 'pending_approval',
        ]);
        $this->approval = ApprovalList::create([
            'user_id' => $this->owner->user_id, 'cafe_id' => $cafe->cafe_id,
            'branch_id' => $this->branch->branch_id, 'status' => 'pending_approval',
        ]);
    }

    private function decide(array $body)
    {
        return $this->withToken($this->admin->createToken('test')->plainTextToken)
            ->patchJson("/api/admin/branches/{$this->branch->uuid}/status", $body);
    }

    public function test_approving_activates_the_branch(): void
    {
        $this->decide(['status' => 'approved'])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('active', $this->branch->fresh()->status);
        $this->assertSame('approved', $this->approval->fresh()->status);
        $this->assertSame($this->admin->user_id, $this->approval->fresh()->reviewed_by);
    }

    public function test_rejecting_records_the_reason(): void
    {
        $this->decide(['status' => 'rejected', 'reason' => 'Blurry BIR.'])->assertOk();

        $this->assertSame('rejected', $this->branch->fresh()->status);
        $this->assertSame('rejected', $this->approval->fresh()->status);
        $this->assertSame('Blurry BIR.', $this->approval->fresh()->reason);
    }

    public function test_a_decided_branch_cannot_be_decided_again(): void
    {
        $this->decide(['status' => 'approved'])->assertOk();

        $this->decide(['status' => 'rejected', 'reason' => 'Changed my mind'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertSame('active', $this->branch->fresh()->status);
    }

    public function test_the_snapshot_says_it_is_a_side_branch(): void
    {
        // The approvals page uses this to open branch links on the Branch tab
        $this->withToken($this->admin->createToken('test')->plainTextToken)
            ->getJson("/api/admin/approvals/{$this->approval->uuid}/snapshot")
            ->assertOk()
            ->assertJsonPath('branch.branch_type', 'side')
            ->assertJsonPath('approval.status', 'pending_approval');
    }

    public function test_the_owner_endpoint_refuses_an_active_owner(): void
    {
        // What the page used to call for a branch opened from a notification
        $this->withToken($this->admin->createToken('test')->plainTextToken)
            ->patchJson("/api/admin/owners/{$this->owner->uuid}/status", ['status' => 'approved'])
            ->assertStatus(422);
        $this->assertSame('pending_approval', $this->branch->fresh()->status);
    }
}
