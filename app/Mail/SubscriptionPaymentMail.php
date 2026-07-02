<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SubscriptionPaymentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $planName,
        public readonly string $status, // 'active' or 'failed'
        public readonly ?string $amount = null,
        public readonly ?string $endDate = null,
    ) {}

    public function build()
    {
        $subject = $this->status === 'active'
            ? 'Your BrewSpot Subscription is Active'
            : 'Your BrewSpot Subscription Payment Failed';

        return $this->subject($subject)->view('emails.subscription-payment');
    }
}