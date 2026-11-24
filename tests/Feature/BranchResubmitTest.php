<?php

namespace Tests\Feature;

use App\Models\ApprovalList;
use App\Models\BranchDocument;
use App\Models\CafeBranch;
use App\Models\Feature;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A rejected side branch: the owner sees why, edits and resubmits it (when the admin
 * allowed it), and admins see it as a resubmission. Owners may also withdraw a
 * branch still under review, or delete a rejected one.
 */
class BranchResubmitTest extends FloorPlanTestCase
{
    private User $admin;
    private CafeBranch $side;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->subscriptionPlan->features()->attach(
            Feature::create(['key' => 'multi_branch', 'name' => 'multi_branch', 'is_active' => true])->feature_id
        );

        $this->admin = $this->makeUser('Admin');

        $this->side = CafeBranch::create([
            'cafe_id' => $this->cafe->cafe_id, 'branch_name' => 'Lanang', 'cafe_email' => 'lanang@test.local',
            'cafe_phonenumber' => '+639171111111', 'address' => 'Lanang, Davao', 'branch_type' => 'side',
            'status' => 'pending_approval',
        ]);
        BranchDocument::create([
            'branch_id' => $this->side->branch_id, 'doc_type' => 'BIR', 'file' => 'old-bir.pdf',
            'tin_number' => '123-456-789-00001', 'vat' => 'non-vat', 'registered_at' => '2026-01-10',
        ]);
        ApprovalList::create([
            'user_id' => $this->owner->user_id, 'cafe_id' => $this->cafe->cafe_id,
            'branch_id' => $this->side->branch_id, 'status' => 'pending_approval',
        ]);
    }

    private function reject(bool $allowResubmit = true): void
    {
        $this->api('PATCH', "/api/admin/branches/{$this->side->uuid}/status", $this->tokenFor($this->admin), [
            'status' => 'rejected', 'reason' => 'TIN does not match the COR', 'allow_resubmit' => $allowResubmit,
        ])->assertOk();
    }

    private function resubmitPayload(array $overrides = []): array
    {
        return [
            'branch_name'       => 'Lanang Branch',
            'address'           => 'JP Laurel Ave, Lanang, Davao',
            'cafe_email'        => 'lanang@test.local',
            'cafe_phonenumber'  => '09171111111',
            'bir_registered_at' => '2026-01-10',
            'tin_number'        => '123-456-789-00002',
            'vat'               => 'non-vat',
            ...$overrides,
        ];
    }

    private function resubmit(array $overrides = [])
    {
        return $this->api('POST', "/api/owner/branches/{$this->side->uuid}/resubmit", $this->tokenFor($this->owner), $this->resubmitPayload($overrides));
    }

    public function test_owner_sees_the_rejection_and_resubmits_the_corrected_branch(): void
    {
        $this->reject();

        $this->api('GET', "/api/owner/branches/{$this->side->uuid}", $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonPath('branch.status', 'rejected')
            ->assertJsonPath('review.status', 'rejected')
            ->assertJsonPath('review.reason', 'TIN does not match the COR')
            ->assertJsonPath('review.can_resubmit', true);

        $this->resubmit(['bir_file' => UploadedFile::fake()->create('new-bir.pdf', 100, 'application/pdf')])
            ->assertOk()
            ->assertJsonPath('branch.status', 'pending_approval')
            ->assertJsonPath('branch.branch_name', 'Lanang Branch');

        $side = $this->side->fresh('documents');
        $this->assertSame('JP Laurel Ave, Lanang, Davao', $side->address);
        $bir = $side->documents->firstWhere('doc_type', 'BIR');
        $this->assertSame('123-456-789-00002', $bir->tin_number);
        $this->assertNotSame('old-bir.pdf', $bir->file);

        // A second approval entry, labelled a resubmission everywhere admins look.
        $entries = ApprovalList::where('branch_id', $this->side->branch_id)->orderBy('approval_id')->get();
        $this->assertSame(['rejected', 'pending_approval'], $entries->pluck('status')->all());
        $this->assertTrue($entries->last()->isResubmission());
        $this->assertSame(1, ApprovalList::query()->resubmission()->count());

        $this->api('GET', "/api/owner/branches/{$this->side->uuid}", $this->tokenFor($this->owner))
            ->assertJsonPath('review.resubmitted', true);

        $history = $this->api('GET', "/api/admin/owners/{$this->owner->uuid}", $this->tokenFor($this->admin))
            ->assertOk()->json('application_history');
        $this->assertSame(['branch_resubmission', 'branch'], array_column($history, 'kind'));

        $notice = $this->admin->fresh()->notifications->first()->data;
        $this->assertSame('Branch resubmitted for review', $notice['title']);
    }

    public function test_resubmitting_without_a_new_file_keeps_the_current_bir_file(): void
    {
        $this->reject();

        $this->resubmit()->assertOk();

        $bir = $this->side->fresh('documents')->documents->firstWhere('doc_type', 'BIR');
        $this->assertSame('old-bir.pdf', $bir->file);
        $this->assertSame('123-456-789-00002', $bir->tin_number);
    }

    public function test_a_final_rejection_cannot_be_resubmitted(): void
    {
        $this->reject(allowResubmit: false);

        $this->api('GET', "/api/owner/branches/{$this->side->uuid}", $this->tokenFor($this->owner))
            ->assertJsonPath('review.can_resubmit', false);

        $this->resubmit()->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame('rejected', $this->side->fresh()->status);
    }

    public function test_only_rejected_branches_can_be_resubmitted(): void
    {
        $this->resubmit()->assertStatus(422); // still pending
        $this->assertSame(1, ApprovalList::where('branch_id', $this->side->branch_id)->count());
    }

    public function test_owner_withdraws_a_pending_branch(): void
    {
        $this->api('DELETE', "/api/owner/branches/{$this->side->uuid}", $this->tokenFor($this->owner))->assertOk();

        $this->assertSoftDeleted($this->side);
        $this->assertSame('withdrawn', ApprovalList::where('branch_id', $this->side->branch_id)->value('status'));
        $this->assertSame(0, ApprovalList::where('status', 'pending_approval')->count());

        $history = $this->api('GET', "/api/admin/owners/{$this->owner->uuid}", $this->tokenFor($this->admin))
            ->json('application_history');
        $this->assertSame(['withdrawn'], array_column($history, 'status'));
    }

    public function test_owner_deletes_a_rejected_branch(): void
    {
        $this->reject(allowResubmit: false);

        $this->api('DELETE', "/api/owner/branches/{$this->side->uuid}", $this->tokenFor($this->owner))->assertOk();

        $this->assertSoftDeleted($this->side);
        $this->assertSame('rejected', ApprovalList::where('branch_id', $this->side->branch_id)->value('status'));
    }
}
