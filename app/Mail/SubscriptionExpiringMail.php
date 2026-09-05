<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $planName,
        public readonly string $endDate,
        public readonly int $daysRemaining,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BrewSpot — Notice of Upcoming Subscription Expiration',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-expiring',
            with: [
                'ownerName'     => $this->ownerName,
                'planName'      => $this->planName,
                'endDate'       => $this->endDate,
                'daysRemaining' => $this->daysRemaining,
            ],
        );
    }
}