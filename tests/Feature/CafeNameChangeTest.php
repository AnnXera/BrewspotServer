<?php

namespace Tests\Feature;

use App\Models\BranchDocument;
use App\Models\Cafe;
use App\Models\CafeUpdateRequest;
use App\Models\Feature;
use App\Models\User;
use App\Services\BranchService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Renaming a cafe: the owner sends the new name with an updated COR for every
 * branch, an admin approves or declines it on the Cafe Updates tab, and approval
 * swaps the name and each branch's BIR document together.
 */
class CafeNameChangeTest extends FloorPlanTestCase
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

    private function documents(?array $branches = null): array
    {
        $docs = [];
        foreach ($branches ?? [$this->mainBranch, $this->otherBranch] as $branch) {
            $docs[$branch->uuid] = [
                'file'          => UploadedFile::fake()->create("cor-{$branch->branch_name}.pdf", 100, 'application/pdf'),
                'registered_at' => '2026-10-01',
            ];
        }

        return $docs;
    }

    private function submit(string $name = 'Brew Haven Roasters', ?array $documents = null, ?User $owner = null)
    {
        return $this->api('POST', '/api/owner/cafe-name-change', $this->tokenFor($owner ?? $this->owner), [
            'new_name'  => $name,
            'documents' => $documents ?? $this->documents(),
        ]);
    }

    private function review(string $uuid, string $status, array $extra = [])
    {
        return $this->api('PATCH', "/api/admin/cafe-updates/{$uuid}/status", $this->tokenFor($this->admin), [
            'status' => $status, ...$extra,
        ]);
    }

    public function test_owner_requests_a_rename_and_the_old_name_stays_until_review(): void
    {
        $uuid = $this->submit()
            ->assertCreated()
            ->assertJsonPath('request.status', 'pending_approval')
            ->assertJsonPath('request.old_name', 'Test Cafe')
            ->assertJsonPath('request.new_name', 'Brew Haven Roasters')
            ->assertJsonCount(2, 'request.documents')
            ->assertJsonMissingPath('request.reviewed_by')
            ->json('request.uuid');

        $this->assertSame('Test Cafe', $this->cafe->fresh()->cafe_name);
        $this->assertSame(1, $this->admin->notifications()->count());

        $this->api('GET', '/api/owner/cafe-name-change', $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonPath('cafe_name', 'Test Cafe')
            ->assertJsonPath('request.uuid', $uuid)
            ->assertJsonCount(2, 'branches');

        // Only one request at a time
        $this->submit('Another Name')->assertStatus(422);
    }

    public function test_every_branch_needs_its_updated_cor(): void
    {
        $this->submit(documents: $this->documents([$this->mainBranch]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ["documents.{$this->otherBranch->uuid}.file"]]);

        $this->submit('Test Cafe')->assertStatus(422)->assertJsonStructure(['errors' => ['new_name']]);

        $this->assertSame(0, CafeUpdateRequest::count());
    }

    public function test_approval_renames_the_cafe_and_replaces_each_branch_cor(): void
    {
        $uuid = $this->submit()->json('request.uuid');

        $this->review($uuid, 'approved')
            ->assertOk()
            ->assertJsonPath('request.status', 'approved')
            ->assertJsonPath('request.reviewed_by', trim("{$this->admin->firstname} {$this->admin->lastname}"));

        $this->assertSame('Brew Haven Roasters', $this->cafe->fresh()->cafe_name);

        foreach ([[$this->mainBranch, '123-456-789-00000'], [$this->otherBranch, '123-456-789-00001']] as [$branch, $tin]) {
            $current = BranchDocument::where('branch_id', $branch->branch_id)->where('doc_type', 'BIR')->sole();
            $this->assertSame('2026-10-01', $current->registered_at->toDateString());
            $this->assertSame($tin, $current->tin_number); // the TIN and VAT carry over
            $this->assertStringContainsString('name_changes', $current->file);

            // The old COR is kept on record
            $this->assertSame(1, BranchDocument::onlyTrashed()->where('branch_id', $branch->branch_id)->count());
        }

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->review($uuid, 'rejected')->assertStatus(422); // already decided
    }

    public function test_a_final_rejection_blocks_that_name_but_not_another(): void
    {
        $uuid = $this->submit()->json('request.uuid');

        $this->review($uuid, 'rejected', ['reason' => 'COR still shows the old name', 'allow_resubmit' => false])
            ->assertOk()
            ->assertJsonPath('request.status', 'rejected');

        $this->assertSame('Test Cafe', $this->cafe->fresh()->cafe_name);
        $this->assertSame('old-' . $this->mainBranch->uuid . '.pdf',
            BranchDocument::where('branch_id', $this->mainBranch->branch_id)->value('file'));

        $this->api('GET', '/api/owner/cafe-name-change', $this->tokenFor($this->owner))
            ->assertJsonPath('request.status', 'rejected')
            ->assertJsonPath('request.reason', 'COR still shows the old name')
            ->assertJsonPath('request.can_resubmit', false);

        $this->submit('brew haven roasters')->assertStatus(422)->assertJsonStructure(['errors' => ['new_name']]);

        $this->submit('Brew Haven Coffee')->assertCreated()->assertJsonPath('request.status', 'resubmitted');

        $this->api('GET', '/api/admin/cafe-updates/stats', $this->tokenFor($this->admin))
            ->assertOk()
            ->assertJsonPath('stats.pending', 0)
            ->assertJsonPath('stats.resubmitted', 1)
            ->assertJsonPath('stats.rejected', 1);

        $this->api('GET', '/api/admin/cafe-updates?status=resubmitted', $this->tokenFor($this->admin))
            ->assertOk()
            ->assertJsonCount(1, 'requests.data')
            ->assertJsonPath('requests.data.0.new_name', 'Brew Haven Coffee')
            ->assertJsonPath('requests.data.0.owner.email', 'owner@test.local');
    }

    public function test_owner_can_cancel_a_pending_request(): void
    {
        $uuid = $this->submit()->json('request.uuid');

        $this->api('DELETE', "/api/owner/cafe-name-change/{$uuid}", $this->tokenFor($this->owner))->assertOk();
        $this->assertSame('withdrawn', CafeUpdateRequest::where('uuid', $uuid)->value('status'));

        $this->review($uuid, 'approved')->assertStatus(422);
        $this->submit('Brew Haven Coffee')->assertCreated(); // free to send another
    }

    public function test_branches_cannot_be_added_while_a_rename_is_pending(): void
    {
        $this->subscriptionPlan->features()->attach(
            Feature::create(['key' => 'multi_branch', 'name' => 'multi_branch', 'is_active' => true])->feature_id
        );

        $this->submit()->assertCreated();

        $result = app(BranchService::class)->createBranch($this->owner->fresh(), []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('name change', $result['message']);
    }

    public function test_admin_sees_current_and_new_cor_and_other_owners_cannot_open_them(): void
    {
        $uuid = $this->submit()->json('request.uuid');

        $detail = $this->api('GET', "/api/admin/cafe-updates/{$uuid}", $this->tokenFor($this->admin))
            ->assertOk()
            ->assertJsonPath('request.documents.0.current_document.tin_number', '123-456-789-00000')
            ->json('request.documents.0');

        $this->api('GET', "/api/documents/cafe-update/{$detail['id']}", $this->tokenFor($this->admin))->assertOk();
        $this->api('GET', "/api/documents/cafe-update/{$detail['id']}", $this->tokenFor($this->owner))->assertOk();

        $stranger = $this->makeUser('Cafe Owner');
        Cafe::create(['user_id' => $stranger->user_id, 'cafe_name' => 'Other Cafe']);
        $this->api('GET', "/api/documents/cafe-update/{$detail['id']}", $this->tokenFor($stranger))->assertForbidden();

        // The admin's Application History shows the request
        $this->api('GET', "/api/admin/owners/{$this->owner->uuid}/application-history", $this->tokenFor($this->admin))
            ->assertOk()
            ->assertJsonFragment(['kind' => 'name_change', 'status' => 'pending_approval']);
    }
}
