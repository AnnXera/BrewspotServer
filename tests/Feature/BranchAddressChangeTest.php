<?php

namespace Tests\Feature;

use App\Models\BranchDocument;
use App\Models\CafeUpdateRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Moving a branch: the owner sends the new address with the updated COR showing it,
 * an admin approves or declines it on the Cafe Updates tab, and approval swaps the
 * address and that branch's BIR document together. Only that branch is touched.
 */
class BranchAddressChangeTest extends FloorPlanTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->admin = $this->makeUser('Admin');

        foreach ([[$this->mainBranch, '123-456-789-00000'], [$this->otherBranch, '123-456-789-00001']] as [$branch, $tin]) {
            BranchDocument::create([
                'branch_id' => $branch->branch_id, 'doc_type' => 'BIR', 'file' => "old-{$branch->uuid}.pdf",
                'tin_number' => $tin, 'vat' => 'non-vat', 'registered_at' => '2026-01-10',
            ]);
        }
    }

    private function submit(string $address = '88 Rizal St, Davao City', ?User $owner = null, ?string $branchUuid = null)
    {
        return $this->api('POST', '/api/owner/branches/' . ($branchUuid ?? $this->otherBranch->uuid) . '/address-change',
            $this->tokenFor($owner ?? $this->owner), [
                'new_address'   => $address,
                'file'          => UploadedFile::fake()->create('cor.pdf', 100, 'application/pdf'),
                'registered_at' => '2026-10-01',
            ]);
    }

    private function review(string $uuid, string $status, array $extra = [])
    {
        return $this->api('PATCH', "/api/admin/cafe-updates/{$uuid}/status", $this->tokenFor($this->admin), [
            'status' => $status, ...$extra,
        ]);
    }

    public function test_owner_requests_a_move_and_the_old_address_stays_until_review(): void
    {
        $uuid = $this->submit()
            ->assertCreated()
            ->assertJsonPath('request.type', 'address_change')
            ->assertJsonPath('request.status', 'pending_approval')
            ->assertJsonPath('request.old_address', 'Davao City')
            ->assertJsonPath('request.new_address', '88 Rizal St, Davao City')
            ->assertJsonPath('request.branch.uuid', $this->otherBranch->uuid)
            ->assertJsonCount(1, 'request.documents')
            ->json('request.uuid');

        $this->assertSame('Davao City', $this->otherBranch->fresh()->address);
        $this->assertSame(1, $this->admin->notifications()->count());

        $this->api('GET', "/api/owner/branches/{$this->otherBranch->uuid}/address-change", $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonPath('request.uuid', $uuid)
            ->assertJsonPath('blocked', 'An address change for this branch is already waiting for review. Cancel it first to send a different one.');

        // One per branch: a second one for it waits, another branch can still ask
        $this->submit('Somewhere Else')->assertStatus(422);
        $this->submit('12 Main Ave, Tagum', branchUuid: $this->mainBranch->uuid)->assertCreated();

        // The rename banner only tracks name changes
        $this->api('GET', '/api/owner/cafe-name-change', $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonPath('request', null);
    }

    public function test_the_new_address_and_cor_are_required(): void
    {
        $this->submit('  davao   city ')->assertStatus(422)->assertJsonStructure(['errors' => ['new_address']]);

        $this->api('POST', "/api/owner/branches/{$this->otherBranch->uuid}/address-change", $this->tokenFor($this->owner), [
            'new_address' => '88 Rizal St',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['file', 'registered_at']]);

        $this->assertSame(0, CafeUpdateRequest::count());
    }

    public function test_approval_moves_the_branch_and_replaces_only_its_cor(): void
    {
        $uuid = $this->submit()->json('request.uuid');

        $this->review($uuid, 'approved')
            ->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonPath('request.documents.0.current_document.tin_number', '123-456-789-00001');

        $this->assertSame('88 Rizal St, Davao City', $this->otherBranch->fresh()->address);

        $current = BranchDocument::where('branch_id', $this->otherBranch->branch_id)->where('doc_type', 'BIR')->sole();
        $this->assertSame('2026-10-01', $current->registered_at->toDateString());
        $this->assertSame('123-456-789-00001', $current->tin_number); // the TIN and VAT carry over
        $this->assertStringContainsString('address_changes', $current->file);
        $this->assertSame(1, BranchDocument::onlyTrashed()->where('branch_id', $this->otherBranch->branch_id)->count());

        // The other branch keeps its address and COR
        $this->assertSame('Davao City', $this->mainBranch->fresh()->address);
        $this->assertSame(0, BranchDocument::onlyTrashed()->where('branch_id', $this->mainBranch->branch_id)->count());

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->review($uuid, 'rejected')->assertStatus(422); // already decided
    }

    public function test_a_final_rejection_blocks_that_address_but_not_another(): void
    {
        $uuid = $this->submit()->json('request.uuid');

        $this->review($uuid, 'rejected', ['reason' => 'COR still shows the old address', 'allow_resubmit' => false])
            ->assertOk();

        $this->assertSame('Davao City', $this->otherBranch->fresh()->address);

        $this->submit('88 rizal st, davao city')->assertStatus(422)->assertJsonStructure(['errors' => ['new_address']]);
        $this->submit('90 Rizal St, Davao City')->assertCreated()->assertJsonPath('request.status', 'resubmitted');

        $this->api('GET', '/api/admin/cafe-updates/stats', $this->tokenFor($this->admin))
            ->assertJsonPath('stats.resubmitted', 1)
            ->assertJsonPath('stats.rejected', 1);
    }

    public function test_a_rename_and_an_address_change_wait_for_each_other(): void
    {
        $this->submit()->assertCreated();

        $docs = [];
        foreach ([$this->mainBranch, $this->otherBranch] as $branch) {
            $docs[$branch->uuid] = ['file' => UploadedFile::fake()->create('cor.pdf', 100, 'application/pdf'), 'registered_at' => '2026-10-01'];
        }
        $this->api('POST', '/api/owner/cafe-name-change', $this->tokenFor($this->owner), [
            'new_name' => 'Brew Haven Roasters', 'documents' => $docs,
        ])->assertStatus(422)->assertJsonPath('success', false);

        // Once the address change is decided, the rename can go; then the address change waits
        CafeUpdateRequest::query()->update(['status' => 'withdrawn']);
        $this->api('POST', '/api/owner/cafe-name-change', $this->tokenFor($this->owner), [
            'new_name' => 'Brew Haven Roasters', 'documents' => $docs,
        ])->assertCreated();

        $this->submit('90 Rizal St')->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_owner_can_cancel_and_strangers_cannot_ask(): void
    {
        $uuid = $this->submit()->json('request.uuid');

        $this->api('DELETE', "/api/owner/branches/{$this->otherBranch->uuid}/address-change/{$uuid}", $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonPath('message', 'Address change cancelled.');
        $this->review($uuid, 'approved')->assertStatus(422);

        $stranger = $this->makeUser('Cafe Owner');
        $this->submit(owner: $stranger)->assertStatus(422)->assertJsonPath('message', 'Branch not found.');

        // Only an approved branch can ask
        $this->otherBranch->update(['status' => 'pending_approval']);
        $this->submit()->assertStatus(422);
    }

    public function test_admin_list_and_history_show_the_branch(): void
    {
        $this->submit()->assertCreated();

        $this->api('GET', '/api/admin/cafe-updates?search=Rizal', $this->tokenFor($this->admin))
            ->assertOk()
            ->assertJsonCount(1, 'requests.data')
            ->assertJsonPath('requests.data.0.type', 'address_change')
            ->assertJsonPath('requests.data.0.branch.branch_name', $this->otherBranch->branch_name);

        $this->api('GET', "/api/admin/owners/{$this->owner->uuid}/application-history", $this->tokenFor($this->admin))
            ->assertOk()
            ->assertJsonFragment(['kind' => 'address_change', 'status' => 'pending_approval']);
    }
}
