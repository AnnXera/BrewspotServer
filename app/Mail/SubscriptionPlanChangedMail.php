<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SubscriptionPlanChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $newPlanName,
        public readonly string $effectiveDate,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'BrewSpot — Subscription Plan Update Scheduled',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-plan-changed',
            with: [
                'ownerName'     => $this->ownerName,
                'newPlanName'   => $this->newPlanName,
                'effectiveDate' => $this->effectiveDate,
            ],
        );
    }
}
