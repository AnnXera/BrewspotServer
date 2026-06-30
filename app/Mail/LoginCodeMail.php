<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $email
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BrewSpot — Your Login Verification Code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.login-code',
            with: [
                'code'  => $this->code,
                'email' => $this->email,
            ],
        );
    }
}