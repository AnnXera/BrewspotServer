<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The owner edits a branch's contact email, phone number and picture. Each edit is
 * written to the branch's activity log (seen by the owner and the branch's managers)
 * and announced to the branch's managers. Managers cannot make these edits.
 */
class BranchDetailsUpdateTest extends FloorPlanTestCase
{
    private function patchBranch(User $actor, array $data)
    {
        return $this->api('PATCH', "/api/owner/branches/{$this->mainBranch->uuid}", $this->tokenFor($actor), $data);
    }

    private function kinds(User $user): array
    {
        return $user->fresh()->notifications->pluck('data.kind')->all();
    }

    public function test_owner_updates_contacts_and_it_is_logged_and_announced(): void
    {
        $oldEmail = $this->mainBranch->cafe_email;

        $this->patchBranch($this->owner, ['cafe_email' => 'new@test.local', 'cafe_phonenumber' => '09181234567'])
            ->assertOk()
            ->assertJsonPath('branch.cafe_email', 'new@test.local')
            ->assertJsonPath('branch.cafe_phonenumber', '+639181234567');

        $log = ActivityLog::where('action', ActivityLog::BRANCH_DETAILS_UPDATED)->sole();
        $this->assertSame(ActivityLog::CATEGORY_BRANCH, $log->category);
        $this->assertSame($this->mainBranch->branch_id, $log->branch_id);
        $this->assertSame($this->owner->user_id, $log->user_id);
        $this->assertSame('Updated branch email, phone number', $log->description);
        $this->assertSame([
            ['field' => 'Email', 'before' => $oldEmail, 'after' => 'new@test.local'],
            ['field' => 'Phone number', 'before' => '+639170000000', 'after' => '+639181234567'],
        ], $log->meta['changes']);

        // The manager sees it in their branch log, and the owner in theirs.
        $this->api('GET', $this->base('manager') . '/activity-logs', $this->tokenFor($this->manager))
            ->assertOk()->assertJsonPath('counts.branch', 1)->assertJsonPath('logs.0.action', 'branch.details_updated');
        $this->api('GET', '/api/owner/activity-logs?category=branch', $this->tokenFor($this->owner))
            ->assertOk()->assertJsonPath('counts.branch', 1);

        // The manager gets a notice; the owner and cashier do not.
        $this->assertSame(['branch_details_updated'], $this->kinds($this->manager));
        $this->assertSame([], $this->kinds($this->owner));
        $this->assertSame([], $this->kinds($this->cashier));

        $notice    = $this->manager->fresh()->notifications->first()->data;
        $ownerName = trim("{$this->owner->firstname} {$this->owner->lastname}");
        $this->assertSame('Main Branch details were updated', $notice['title']);
        $this->assertSame("{$ownerName} (Owner) changed the branch email and phone number.", $notice['body']);
        $this->assertSame("/manager/cafe?branch={$this->mainBranch->uuid}", $notice['link']);
    }

    public function test_picture_change_is_logged_without_values(): void
    {
        Storage::fake('public');

        // create() rather than image(): no GD needed.
        $this->patchBranch($this->owner, ['cafe_picture' => UploadedFile::fake()->create('front.jpg', 50, 'image/jpeg')])->assertOk();

        $log = ActivityLog::where('action', ActivityLog::BRANCH_DETAILS_UPDATED)->sole();
        $this->assertSame([['field' => 'Picture']], $log->meta['changes']);
        $this->assertSame(['branch_details_updated'], $this->kinds($this->manager));
    }

    public function test_saving_unchanged_values_logs_and_notifies_nothing(): void
    {
        // The fixture gives every branch the same phone; make the other branch's unique.
        $this->otherBranch->update(['cafe_phonenumber' => '+639175555555']);

        $this->patchBranch($this->owner, [
            'cafe_email'       => $this->mainBranch->cafe_email,
            'cafe_phonenumber' => '+639170000000',
        ])->assertOk();

        $this->assertSame(0, ActivityLog::where('action', ActivityLog::BRANCH_DETAILS_UPDATED)->count());
        $this->assertSame([], $this->kinds($this->manager));
    }

    public function test_landline_is_accepted(): void
    {
        $this->patchBranch($this->owner, ['cafe_phonenumber' => '082-299-1234'])
            ->assertOk()->assertJsonPath('branch.cafe_phonenumber', '0822991234');
    }

    public function test_manager_cannot_update_branch_details(): void
    {
        $this->patchBranch($this->manager, ['cafe_email' => 'sneaky@test.local'])->assertForbidden();

        $this->assertNotSame('sneaky@test.local', $this->mainBranch->fresh()->cafe_email);
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::BRANCH_DETAILS_UPDATED)->count());
    }
}
