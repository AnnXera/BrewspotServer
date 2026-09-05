<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BranchStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $firstname,
        public readonly string $branchName,
        public readonly string $status, // 'approved' or 'rejected'
        public readonly ?string $reason = null
    ) {}

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
            ],
        );
    }
}