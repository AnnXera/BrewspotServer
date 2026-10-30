<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Not queued itself: SendSubscriptionNotice queues and retries it.
class SubscriptionExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $planName,
        public readonly string $endDate,
        public readonly int $daysRemaining,
        public readonly ?string $renewUrl = null,
        /** The plan the owner will renew into: the booked change if any; null for a trial with nothing booked. */
        public readonly ?string $renewPlanName = null,
        /** When payment for the next term opens: the day the owner's paid days run out. */
        public readonly ?string $renewalOpensOn = null,
        /** Payment for the next term is already open, so the owner can renew right now. */
        public readonly bool $renewalOpen = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = match (true) {
            $this->renewPlanName === null => 'BrewSpot: Your trial is ending soon, choose a plan',
            $this->renewalOpen            => 'BrewSpot: Your subscription is ending, renew now',
            default                       => 'BrewSpot: Notice of Upcoming Subscription Expiration',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-expiring',
            with: [
                'ownerName'      => $this->ownerName,
                'planName'       => $this->planName,
                'endDate'        => $this->endDate,
                'daysRemaining'  => $this->daysRemaining,
                'renewUrl'       => $this->renewUrl,
                'renewPlanName'  => $this->renewPlanName,
                'renewalOpensOn' => $this->renewalOpensOn,
                'renewalOpen'    => $this->renewalOpen,
            ],
        );
    }
}
