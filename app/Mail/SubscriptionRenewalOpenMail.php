<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Not queued itself: SendSubscriptionNotice queues and retries it.
class SubscriptionRenewalOpenMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $planName,
        /** The plan the owner will pay for — the booked change if any; null for a trial with nothing booked. */
        public readonly ?string $renewPlanName,
        public readonly string $endDate,
        public readonly string $renewUrl,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match (true) {
            $this->renewPlanName === null              => 'BrewSpot — Your trial is ending, choose a plan',
            $this->renewPlanName !== $this->planName   => "BrewSpot — You can now pay for your {$this->renewPlanName}",
            default                                    => 'BrewSpot — Your subscription is ready to renew',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-renewal-open',
            with: [
                'ownerName'     => $this->ownerName,
                'planName'      => $this->planName,
                'renewPlanName' => $this->renewPlanName,
                'endDate'       => $this->endDate,
                'renewUrl'      => $this->renewUrl,
            ],
        );
    }
}
