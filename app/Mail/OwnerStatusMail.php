<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OwnerStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $firstname,
        public readonly string $status,
        public readonly ?string $ownerUuid = null
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
            view: 'emails.owner-status',
            with: [
                'firstname'   => $this->firstname,
                'status'      => $this->status,
                'heading'     => $this->headingForStatus(),
                'bodyMessage' => $this->messageForStatus(),
                'setupUrl'    => $this->status === 'approved'
                    ? rtrim(config('app.frontend_url'), '/') . "/setup-password/{$this->ownerUuid}"
                    : null,
            ],
        );
    }

    private function subjectForStatus(): string
    {
        return match ($this->status) {
            'approved' => 'BrewSpot — Application Approved!',
            'rejected' => 'BrewSpot — Application Status',
            'inactive' => 'BrewSpot — Account Suspended',
            default    => 'BrewSpot — Account Status Update',
        };
    }

    private function headingForStatus(): string
    {
        return match ($this->status) {
            'approved' => 'Your Application Has Been Approved! 🎉',
            'rejected' => 'Application Update',
            'inactive' => 'Account Suspended',
            default    => 'Account Status Update',
        };
    }

    private function messageForStatus(): string
    {
        return match ($this->status) {
            'approved' => 'Congratulations! Your cafe application has been approved. Click the button below to set up your password and start your free trial.',
            'rejected' => 'After careful review, we regret to inform you that your cafe application has not been approved at this time. If you believe this was a mistake, please contact our support team.',
            'inactive' => 'Your account has been suspended by our admin team. If you believe this is an error, please reach out to our support team for clarification.',
            default    => 'Your account status has been updated. Please log in to view more details.',
        };
    }
}