<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Not queued itself: SendSubscriptionNotice queues and retries it.
class SubscriptionExpiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $planName,
        /** The plan offered for renewal — the booked change if any; null for a trial with nothing booked. */
        public readonly ?string $renewPlanName,
        public readonly string $endedOn,
        public readonly string $renewUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'BrewSpot — Your subscription has expired');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-expired',
            with: [
                'ownerName'     => $this->ownerName,
                'planName'      => $this->planName,
                'renewPlanName' => $this->renewPlanName,
                'endedOn'       => $this->endedOn,
                'renewUrl'      => $this->renewUrl,
            ],
        );
    }
}
