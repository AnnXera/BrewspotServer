<?php

namespace Tests\Feature;

use App\Mail\BranchStatusMail;
use Tests\TestCase;

/**
 * The branch decision email links to the owner's branch page, with a button that
 * fits the outcome: open it, fix and resubmit it, or just view it (final rejection).
 */
class BranchStatusMailTest extends TestCase
{
    private function render(string $status, bool $canResubmit = true): string
    {
        config(['app.frontend_url' => 'https://app.brewspot.test/']);

        return (new BranchStatusMail('Ana', 'Lanang', $status, $status === 'rejected' ? 'TIN mismatch' : null, 'abc-123', $canResubmit))->render();
    }

    public function test_approved_email_opens_the_branch(): void
    {
        $html = $this->render('approved');

        $this->assertStringContainsString('href="https://app.brewspot.test/owner/branches/abc-123"', $html);
        $this->assertStringContainsString('Open Branch', $html);
    }

    public function test_rejected_email_offers_to_resubmit(): void
    {
        $html = $this->render('rejected');

        $this->assertStringContainsString('/owner/branches/abc-123', $html);
        $this->assertStringContainsString('Review and Resubmit', $html);
        $this->assertStringContainsString('TIN mismatch', $html);
    }

    public function test_final_rejection_email_only_links_to_view(): void
    {
        $html = $this->render('rejected', canResubmit: false);

        $this->assertStringContainsString('View Branch', $html);
        $this->assertStringContainsString('This decision is final', $html);
        $this->assertStringNotContainsString('Review and Resubmit', $html);
    }

    public function test_no_button_without_a_branch_uuid(): void
    {
        $html = (new BranchStatusMail('Ana', 'Lanang', 'approved'))->render();

        $this->assertStringNotContainsString('Open Branch', $html);
    }
}
