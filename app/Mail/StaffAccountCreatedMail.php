<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $firstname,
        public readonly string $roleName,
        public readonly string $staffUuid
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "BrewSpot — You've Been Added as {$this->roleName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            // NOTE: create resources/views/emails/staff-account-created.blade.php,
            // mirroring your existing emails.owner-status view/layout.
            view: 'emails.staff-account-created',
            with: [
                'firstname' => $this->firstname,
                'roleName'  => $this->roleName,
                'setupUrl'  => rtrim(config('app.frontend_url'), '/') . "/setup-password/{$this->staffUuid}",
            ],
        );
    }
}