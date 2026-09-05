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
        public readonly ?string $ownerUuid = null,
        public readonly ?string $reason = null
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
                'reason'      => $this->status === 'rejected' ? $this->reason : null,
                'setupUrl'    => $this->status === 'approved'
                    ? rtrim(config('app.frontend_url'), '/') . "/setup-password/{$this->ownerUuid}"
                    : null,
            ],
        );
    }

    private function subjectForStatus(): string
    {
        return match ($this->status) {
            'approved'  => 'BrewSpot — Registration Application Approved',
            'rejected'  => 'BrewSpot — Registration Application Status',
            'suspended' => 'BrewSpot — Notice of Account Suspension',
            'active'    => 'BrewSpot — Notice of Account Reactivation',
            'inactive'  => 'BrewSpot — Notice of Account Deactivation',
            default     => 'BrewSpot — Account Status Update',
        };
    }

    private function headingForStatus(): string
    {
        return match ($this->status) {
            'approved'  => 'Registration Application Approved',
            'rejected'  => 'Registration Application Status',
            'suspended' => 'Notice of Account Suspension',
            'active'    => 'Notice of Account Reactivation',
            'inactive'  => 'Notice of Account Deactivation',
            default     => 'Account Status Update',
        };
    }

    private function messageForStatus(): string
    {
        return match ($this->status) {
            'approved'  => 'We are pleased to inform you that your café registration application has been reviewed and approved. Please proceed to establish your account password and initialize your management portal using the link below.',
            'rejected'  => 'Thank you for your interest in the BrewSpot platform. Following a formal review of your application, we regret to inform you that we are unable to approve your registration at this time.',
            'suspended' => 'Please be advised that your BrewSpot merchant account has been suspended by system administration pending review. If you believe this action was taken in error or require further clarification, please contact our support team.',
            'active'    => 'We are pleased to notify you that your BrewSpot merchant account has been reactivated. Full access to the administration portal and associated services has been restored.',
            'inactive'  => 'This notice confirms that your BrewSpot account has been deactivated in accordance with administrative records or your formal request. To restore your account services in the future, please contact our support desk.',
            default     => 'This is a notification that your account status has been updated. Please sign in to your dashboard to review your account details.',
        };
    }
}