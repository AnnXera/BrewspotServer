<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ForgotPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $tempPassword,
        public readonly string $email,
        public readonly string $firstname
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BrewSpot — Your New Temporary Password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.forgot-password',
            with: [
                'tempPassword' => $this->tempPassword,
                'email'        => $this->email,
                'firstname'    => $this->firstname,
            ],
        );
    }
}
