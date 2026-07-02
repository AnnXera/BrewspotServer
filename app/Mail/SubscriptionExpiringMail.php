<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
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

    public function build()
    {
        return $this->subject('Your BrewSpot Subscription is Expiring Soon')
            ->view('emails.subscription-expiring');
    }
}