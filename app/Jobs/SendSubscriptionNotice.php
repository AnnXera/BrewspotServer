<?php

namespace App\Jobs;

use App\Contracts\MailAdapterInterface;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one subscription notice and owns its "sent" marker.
 *
 * The marker is claimed before dispatch so the every-minute scheduler doesn't queue the
 * same notice twice. If every attempt fails, failed() releases the claim, so the next
 * scheduler run queues the notice again instead of it being lost.
 */
class SendSubscriptionNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MARKERS = [
        'expiration_reminder_sent_at',
        'renewal_open_reminder_sent_at',
        'expired_notice_sent_at',
    ];

    public int $tries = 3;

    /** Seconds to wait before the 2nd and 3rd attempts; most SMTP failures are brief. */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $marker,
        public readonly string $to,
        public readonly Mailable $mailable,
    ) {}

    public function handle(MailAdapterInterface $mailer): void
    {
        $mailer->sendMailable($this->to, $this->mailable);
    }

    public function failed(?Throwable $e): void
    {
        if (! in_array($this->marker, self::MARKERS, true)) {
            return;
        }

        Subscription::whereKey($this->subscriptionId)->update([$this->marker => null]);

        Log::channel('owner')->warning('Subscription notice failed; it will be retried on the next scheduler run.', [
            'subscription_id' => $this->subscriptionId,
            'notice'          => $this->marker,
            'error'           => $e?->getMessage(),
        ]);
    }
}
