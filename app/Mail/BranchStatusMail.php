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
        public readonly string $status
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectForStatus(),
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
                'heading'     => $this->headingForStatus(),
                'bodyMessage' => $this->messageForStatus(),
            ],
        );
    }

    private function subjectForStatus(): string
    {
        return match ($this->status) {
            'approved' => "BrewSpot — Branch \"{$this->branchName}\" Approved!",
            'rejected' => "BrewSpot — Branch \"{$this->branchName}\" Application Update",
            'inactive' => "BrewSpot — Branch \"{$this->branchName}\" Suspended",
            default    => "BrewSpot — Branch \"{$this->branchName}\" Status Update",
        };
    }

    private function headingForStatus(): string
    {
        return match ($this->status) {
            'approved' => 'Your New Branch Has Been Approved! 🎉',
            'rejected' => 'Branch Application Update',
            'inactive' => 'Branch Suspended',
            default    => 'Branch Status Update',
        };
    }

    private function messageForStatus(): string
    {
        return match ($this->status) {
            'approved' => "Great news! Your branch \"{$this->branchName}\" has been approved and is now active.",
            'rejected' => "After careful review, your branch \"{$this->branchName}\" has not been approved at this time. If you believe this was a mistake, please contact our support team.",
            'inactive' => "Your branch \"{$this->branchName}\" has been suspended by our admin team. If you believe this is an error, please reach out to our support team for clarification.",
            default    => "The status of your branch \"{$this->branchName}\" has been updated. Please log in to view more details.",
        };
    }
}