<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class BranchStatusMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $firstname,
        public readonly string $branchName,
        public readonly string $status, // 'approved' or 'rejected'
        public readonly ?string $reason = null,
        public readonly ?string $branchUuid = null, // links the button to the owner's branch page
        public readonly bool $canResubmit = true    // rejections only: false when the rejection is final
    ) {}

    /** The owner's branch page, or null when no branch uuid was given. */
    private function branchUrl(): ?string
    {
        return $this->branchUuid
            ? rtrim(config('app.frontend_url'), '/') . "/owner/branches/{$this->branchUuid}"
            : null;
    }

    /** What the button says and the line above it, per outcome. */
    private function action(): array
    {
        return match (true) {
            $this->status === 'approved' => [
                'Open Branch',
                'You can now add employees, set up registers and manage the menu for this branch.',
            ],
            $this->canResubmit => [
                'Review and Resubmit',
                'You can fix the issue above and send the branch back for review. Its details and documents are kept, so you only need to change what the reason mentions.',
            ],
            default => [
                'View Branch',
                'This decision is final, so the branch cannot be resubmitted. You can remove it from your account.',
            ],
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->status === 'approved'
                ? 'BrewSpot — Branch Application Approved'
                : 'BrewSpot — Branch Application Status Update',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.branch-status',
            with: [
                'firstname'   => $this->firstname,
                'branchName'  => $this->branchName,
                'status'      => $this->status,
                'heading'     => $this->status === 'approved' ? 'Branch Application Approved' : 'Branch Application Status Update',
                'bodyMessage' => $this->status === 'approved'
                    ? "We are pleased to inform you that your branch \"{$this->branchName}\" has been approved and is now active on the BrewSpot platform."
                    : "Following a comprehensive review of your submission, we regret to inform you that the application for branch \"{$this->branchName}\" has not been approved at this time.",
                'reason'      => $this->status === 'rejected' ? $this->reason : null,
                'actionUrl'   => $this->branchUrl(),
                'actionLabel' => $this->action()[0],
                'actionNote'  => $this->action()[1],
            ],
        );
    }
}