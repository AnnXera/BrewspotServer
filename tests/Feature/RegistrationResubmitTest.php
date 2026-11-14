<?php

namespace Tests\Feature;

use App\Models\ApprovalList;
use App\Models\BranchDocument;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeDocument;
use App\Models\Role;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A rejected owner edits and resubmits their application instead of starting over,
 * unless the admin made the rejection final.
 */
class RegistrationResubmitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $owner;
    private ApprovalList $approval;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        Storage::fake('public');

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }

        $this->admin = User::create([
            'firstname' => 'Ada',
            'lastname'  => 'Admin',
            'email'     => 'admin@test.local',
            'status'    => 'active',
            'role_id'   => Role::where('role_name', 'Admin')->value('role_id'),
        ]);

        $this->owner = User::create([
            'firstname'    => 'Ana',
            'lastname'     => 'Cruz',
            'username'     => 'anacruz',
            'email'        => 'owner@test.local',
            'phone_number' => '+639171234567',
            'address'      => 'Davao City',
            'status'       => 'pending_approval',
            'role_id'      => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);

        $userFolder = "users/{$this->owner->uuid}";
        Storage::disk('local')->put("{$userFolder}/user_documents/id.png", 'id');
        UserDocument::create([
            'user_id' => $this->owner->user_id,
            'file'    => "{$userFolder}/user_documents/id.png",
            'id_type' => 'passport',
        ]);

        $cafe = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Bean There']);
        $cafeFolder = "{$userFolder}/cafes/{$cafe->uuid}";
        Storage::disk('local')->put("{$cafeFolder}/cafe_documents/dti.pdf", 'dti');
        CafeDocument::create([
            'cafe_id'  => $cafe->cafe_id,
            'doc_type' => 'DTI',
            'file'     => "{$cafeFolder}/cafe_documents/dti.pdf",
        ]);

        $branch = CafeBranch::create([
            'cafe_id'          => $cafe->cafe_id,
            'branch_name'      => 'Main',
            'cafe_email'       => 'cafe@test.local',
            'cafe_phonenumber' => '+639187654321',
            'address'          => 'Matina',
            'branch_type'      => 'main',
            'status'           => 'pending_approval',
        ]);
        $branchFolder = "{$cafeFolder}/branches/{$branch->uuid}";
        Storage::disk('local')->put("{$branchFolder}/branch_documents/bir.pdf", 'bir');
        BranchDocument::create([
            'branch_id'     => $branch->branch_id,
            'doc_type'      => 'BIR',
            'file'          => "{$branchFolder}/branch_documents/bir.pdf",
            'registered_at' => '2026-01-15',
            'tin_number'    => '123-456-789-00000',
            'vat'           => 'non-vat',
        ]);

        $this->approval = ApprovalList::create([
            'user_id'   => $this->owner->user_id,
            'cafe_id'   => $cafe->cafe_id,
            'branch_id' => $branch->branch_id,
            'status'    => 'pending_approval',
        ]);
    }

    private function reject(array $extra = []): void
    {
        $this->withToken($this->admin->createToken('test')->plainTextToken)
            ->patchJson("/api/admin/owners/{$this->owner->uuid}/status", array_merge([
                'status' => 'rejected',
                'reason' => 'BIR certificate is blurry.',
            ], $extra))
            ->assertOk();
    }

    /** Stands in for the send-code / verify-code round trip. */
    private function reverifyEmail(): void
    {
        $this->owner->update(['status' => 'filling_application', 'email_verified_at' => now()]);
    }

    private function resubmitPayload(array $overrides = []): array
    {
        return array_merge([
            'firstname'         => 'Ana',
            'lastname'          => 'Cruz',
            'username'          => 'anacruz',
            'phone_number'      => '+639171234567',
            'owner_address'     => 'Davao City',
            'id_type'           => 'passport',
            'file'              => 'keep',
            'cafe_name'         => 'Bean There',
            'cafe_doc_type'     => 'DTI',
            'dti_sec_file'      => 'keep',
            'branch_name'       => 'Main',
            'cafe_email'        => 'cafe@test.local',
            'cafe_phonenumber'  => '+639187654321',
            'address'           => 'Matina',
            'bir_file'          => 'keep',
            'bir_registered_at' => '2026-01-15',
            'tin_number'        => '123-456-789-00000',
            'vat'               => 'non-vat',
        ], $overrides);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $this->withToken($this->admin->createToken('test')->plainTextToken)
            ->patchJson("/api/admin/owners/{$this->owner->uuid}/status", ['status' => 'rejected'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_rejection_allows_resubmission_by_default(): void
    {
        $this->reject();

        $this->assertTrue($this->approval->fresh()->can_resubmit);
        $this->getJson("/api/auth/application/{$this->owner->uuid}")
            ->assertOk()
            ->assertJsonPath('application.approval.can_resubmit', true);
    }

    public function test_final_rejection_blocks_reapplying(): void
    {
        $this->reject(['allow_resubmit' => false]);

        $this->assertFalse($this->approval->fresh()->can_resubmit);
        $this->postJson('/api/auth/send-code', ['email' => 'owner@test.local'])
            ->assertJson(['success' => false]);
        $this->assertSame('rejected', $this->owner->fresh()->status);
    }

    public function test_sending_a_code_keeps_the_rejected_application(): void
    {
        $this->reject();

        $this->postJson('/api/auth/send-code', ['email' => 'owner@test.local'])
            ->assertJson(['success' => true]);

        $this->assertSame('email_unverified', $this->owner->fresh()->status);
        $this->assertNotNull(Cafe::find($this->approval->cafe_id), 'cafe should not be archived yet');
    }

    public function test_previous_application_prefills_the_form(): void
    {
        $this->reject();
        $this->reverifyEmail();

        $this->getJson("/api/auth/register/{$this->owner->uuid}/previous-application")
            ->assertOk()
            ->assertJsonPath('application.reason', 'BIR certificate is blurry.')
            ->assertJsonPath('application.cafe_email', 'cafe@test.local')
            ->assertJsonPath('application.tin_number', '123-456-789-00000')
            ->assertJsonPath('application.bir_registered_at', '2026-01-15')
            ->assertJsonPath('application.documents.bir_file', 'bir.pdf')
            ->assertJsonPath('application.documents.file_back', null);
    }

    public function test_previous_application_needs_a_verified_email(): void
    {
        $this->reject();

        $this->getJson("/api/auth/register/{$this->owner->uuid}/previous-application")
            ->assertNotFound();
    }

    public function test_resubmitting_keeps_documents_and_replaces_the_new_upload(): void
    {
        $this->reject();
        $this->reverifyEmail();
        Storage::disk('local')->put('temp/newbir123.pdf', 'sharper bir');

        $this->postJson("/api/auth/register/{$this->owner->uuid}", $this->resubmitPayload([
            'bir_file' => 'temp/newbir123.pdf',
        ]))->assertOk()->assertJson(['success' => true]);

        $owner = $this->owner->fresh();
        $this->assertSame('pending_approval', $owner->status);

        // Old attempt archived, still visible in history
        $this->assertSoftDeleted('cafes', ['cafe_id' => $this->approval->cafe_id]);
        $this->assertSame('rejected', $this->approval->fresh()->status);

        // New attempt pending, with the same TIN, email and phone
        $newApproval = ApprovalList::where('user_id', $owner->user_id)->latest('approval_id')->first();
        $this->assertNotSame($this->approval->approval_id, $newApproval->approval_id);
        $this->assertSame('pending_approval', $newApproval->status);
        $this->assertSame('cafe@test.local', $newApproval->branch->cafe_email);

        $bir = $newApproval->branch->documents()->where('doc_type', 'BIR')->first();
        $this->assertSame('123-456-789-00000', $bir->tin_number);
        $this->assertSame('sharper bir', Storage::disk('local')->get($bir->file));

        $dti = $newApproval->cafe->documents()->first();
        $this->assertStringContainsString($newApproval->cafe->uuid, $dti->file);
        $this->assertSame('dti', Storage::disk('local')->get($dti->file));

        // The archived attempt keeps its own files
        $oldDti = CafeDocument::withTrashed()->where('cafe_id', $this->approval->cafe_id)->first();
        $this->assertTrue(Storage::disk('local')->exists($oldDti->file));
    }

    public function test_cannot_keep_an_id_after_changing_its_type(): void
    {
        $this->reject();
        $this->reverifyEmail();

        $this->postJson("/api/auth/register/{$this->owner->uuid}", $this->resubmitPayload([
            'id_type'   => 'national_id',
            'file_back' => 'keep',
        ]))->assertStatus(422)->assertJsonValidationErrors(['file', 'file_back']);

        $this->assertNotNull(Cafe::find($this->approval->cafe_id));
    }

    public function test_keep_is_refused_on_a_first_application(): void
    {
        $fresh = User::create([
            'email'   => 'new@test.local',
            'status'  => 'filling_application',
            'role_id' => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);

        $this->postJson("/api/auth/register/{$fresh->uuid}", $this->resubmitPayload([
            'username'         => 'newowner',
            'phone_number'     => '+639170000001',
            'cafe_email'       => 'newcafe@test.local',
            'cafe_phonenumber' => '+639170000002',
            'tin_number'       => '999-888-777-00000',
        ]))->assertStatus(422)->assertJsonValidationErrors(['file', 'dti_sec_file', 'bir_file']);
    }

    public function test_application_page_exposes_the_editable_form_only_when_rejected(): void
    {
        $this->getJson("/api/auth/application/{$this->owner->uuid}")
            ->assertOk()
            ->assertJsonPath('application.editable', null);

        $this->reject();

        $this->getJson("/api/auth/application/{$this->owner->uuid}")
            ->assertOk()
            ->assertJsonPath('application.editable.cafe_email', 'cafe@test.local')
            ->assertJsonPath('application.editable.documents.dti_sec_file', 'dti.pdf');
    }

    public function test_rejected_owner_resubmits_from_the_page_without_otp(): void
    {
        $this->reject();
        Storage::disk('local')->put('temp/newbir456.pdf', 'sharper bir');

        $this->postJson("/api/auth/application/{$this->owner->uuid}/resubmit", $this->resubmitPayload([
            'cafe_name' => 'Bean There Cafe',
            'bir_file'  => 'temp/newbir456.pdf',
        ]))->assertOk()->assertJson(['success' => true]);

        $this->assertSame('pending_approval', $this->owner->fresh()->status);
        $this->assertSoftDeleted('cafes', ['cafe_id' => $this->approval->cafe_id]);

        $newApproval = ApprovalList::where('user_id', $this->owner->user_id)->latest('approval_id')->first();
        $this->assertSame('pending_approval', $newApproval->status);
        $this->assertSame('Bean There Cafe', $newApproval->cafe->cafe_name);

        // Admins get a resubmission notice, not a "new application" one
        $notice = $this->admin->fresh()->notifications()->latest()->first();
        $this->assertSame('owner_resubmission', $notice->data['kind']);
        $this->assertSame('Application resubmitted', $notice->data['title']);
        $this->assertSame($newApproval->uuid, $notice->data['approval_uuid']);
        $this->assertStringContainsString('attempt 2', $notice->data['body']);
        $this->assertStringContainsString('BIR certificate is blurry.', $notice->data['body']);

        // Back under review: the page is read-only again and a second resubmit is refused
        $this->getJson("/api/auth/application/{$this->owner->uuid}")
            ->assertJsonPath('application.editable', null);
        $this->postJson("/api/auth/application/{$this->owner->uuid}/resubmit", $this->resubmitPayload())
            ->assertForbidden();
    }

    public function test_page_resubmit_is_refused_while_pending(): void
    {
        $this->postJson("/api/auth/application/{$this->owner->uuid}/resubmit", $this->resubmitPayload())
            ->assertForbidden();

        $this->assertNotNull(Cafe::find($this->approval->cafe_id));
    }

    public function test_page_resubmit_is_refused_after_a_final_rejection(): void
    {
        $this->reject(['allow_resubmit' => false]);

        $this->getJson("/api/auth/application/{$this->owner->uuid}")
            ->assertJsonPath('application.editable', null);
        $this->postJson("/api/auth/application/{$this->owner->uuid}/resubmit", $this->resubmitPayload())
            ->assertForbidden();
    }

    public function test_a_first_application_still_notifies_as_new(): void
    {
        $fresh = User::create([
            'email'   => 'new@test.local',
            'status'  => 'filling_application',
            'role_id' => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);
        foreach (['id1.png', 'dti1.pdf', 'bir1.pdf'] as $name) {
            Storage::disk('local')->put("temp/{$name}", 'x');
        }

        $this->postJson("/api/auth/register/{$fresh->uuid}", $this->resubmitPayload([
            'username'         => 'newowner',
            'phone_number'     => '+639170000001',
            'cafe_email'       => 'newcafe@test.local',
            'cafe_phonenumber' => '+639170000002',
            'tin_number'       => '999-888-777-00000',
            'file'             => 'temp/id1.png',
            'dti_sec_file'     => 'temp/dti1.pdf',
            'bir_file'         => 'temp/bir1.pdf',
        ]))->assertOk();

        $notice = $this->admin->fresh()->notifications()->latest()->first();
        $this->assertSame('owner_application', $notice->data['kind']);
        $this->assertSame('New owner application', $notice->data['title']);
    }

    public function test_cleanup_reverts_an_abandoned_resubmission(): void
    {
        $this->reject();
        $this->reverifyEmail();
        User::whereKey($this->owner->user_id)->update(['updated_at' => now()->subDays(3)]);

        $this->artisan('registrations:cleanup-abandoned')->assertSuccessful();

        $this->assertSame('rejected', $this->owner->fresh()->status);
        $this->assertNotNull(Cafe::find($this->approval->cafe_id));
    }
}
