<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class SubscriptionPlanChangedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $newPlanName,
        public readonly string $effectiveDate,
        /** Set for manually paid plans: the day payment for the new plan opens. Null means it is billed automatically. */
        public readonly ?string $payableFrom = null,
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
                'payableFrom'   => $this->payableFrom,
            ],
        );
    }
}
