<?php

namespace Tests\Feature;

use App\Models\ApprovalList;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin's owner details include every application and branch submission the
 * owner made, newest first, labelled as application, resubmission or branch.
 */
class OwnerApplicationHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_details_list_the_application_history(): void
    {
        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }

        $admin = User::create([
            'firstname' => 'Ada', 'lastname' => 'Admin', 'email' => 'admin@test.local',
            'status' => 'active', 'role_id' => Role::where('role_name', 'Admin')->value('role_id'),
        ]);
        $owner = User::create([
            'firstname' => 'Ana', 'lastname' => 'Cruz', 'email' => 'owner@test.local',
            'status' => 'active', 'role_id' => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);

        $cafe = Cafe::create(['user_id' => $owner->user_id, 'cafe_name' => 'Bean There']);
        $n = 0;
        $branch = function (string $name, string $type, string $status) use ($cafe, &$n) {
            return CafeBranch::create([
                'cafe_id' => $cafe->cafe_id, 'branch_name' => $name, 'cafe_email' => 'b' . (++$n) . '@test.local',
                'cafe_phonenumber' => "+63917000000{$n}", 'address' => 'Davao', 'branch_type' => $type, 'status' => $status,
            ]);
        };

        // First application rejected; its branch was archived when the owner resubmitted.
        $oldMain = $branch('Old Main', 'main', 'rejected');
        ApprovalList::create([
            'user_id' => $owner->user_id, 'cafe_id' => $cafe->cafe_id, 'branch_id' => $oldMain->branch_id,
            'status' => 'rejected', 'reason' => 'Blurry BIR document', 'can_resubmit' => true,
            'reviewed_by' => $admin->user_id, 'reviewed_at' => now()->subDays(5),
        ]);
        $oldMain->delete();

        $main = $branch('Main', 'main', 'active');
        ApprovalList::create([
            'user_id' => $owner->user_id, 'cafe_id' => $cafe->cafe_id, 'branch_id' => $main->branch_id,
            'status' => 'approved', 'reviewed_by' => $admin->user_id, 'reviewed_at' => now()->subDays(3),
        ]);

        \App\Models\BranchDocument::create([
            'branch_id' => $main->branch_id, 'doc_type' => 'BIR', 'file' => 'x.pdf',
            'tin_number' => '123-456-789-00000', 'vat' => 'vat-registered', 'registered_at' => '2026-01-15',
        ]);

        $side = $branch('Lanang', 'side', 'pending_approval');
        ApprovalList::create([
            'user_id' => $owner->user_id, 'cafe_id' => $cafe->cafe_id, 'branch_id' => $side->branch_id,
            'status' => 'pending_approval',
        ]);

        $res = $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson("/api/admin/owners/{$owner->uuid}")
            ->assertOk();

        // BIR details travel with the branch's documents for the branch details modal.
        $bir = collect($res->json('cafes.0.branches'))->firstWhere('branch_name', 'Main')['documents'][0];
        $this->assertSame('123-456-789-00000', $bir['tin_number']);
        $this->assertSame('vat-registered', $bir['vat']);
        $this->assertSame('2026-01-15', \Illuminate\Support\Carbon::parse($bir['registered_at'])->setTimezone(config('app.timezone'))->toDateString());

        $history = $res->json('application_history');
        $this->assertSame(['branch', 'resubmission', 'application'], array_column($history, 'kind'));
        $this->assertSame(['pending_approval', 'approved', 'rejected'], array_column($history, 'status'));
        $this->assertSame(['Lanang', 'Main', 'Old Main'], array_column($history, 'branch_name'));

        $this->assertSame('Blurry BIR document', $history[2]['reason']);
        $this->assertTrue($history[2]['can_resubmit']);
        $this->assertSame('Ada Admin', $history[2]['reviewed_by']);
        $this->assertNull($history[0]['reviewed_at']);
        $this->assertNull($history[0]['reviewed_by']);
    }
}
