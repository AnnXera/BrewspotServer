<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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

    public function envelope(): Envelope
    {
        $subject = $this->status === 'active'
            ? 'BrewSpot — Subscription Payment Confirmation'
            : 'BrewSpot — Notice of Unsuccessful Subscription Payment';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-payment',
            with: [
                'ownerName' => $this->ownerName,
                'planName'  => $this->planName,
                'status'    => $this->status,
                'amount'    => $this->amount,
                'endDate'   => $this->endDate,
            ],
        );
    }
}