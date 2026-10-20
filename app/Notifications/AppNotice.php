<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * An in-app notice for the navbar bell: what kind it is, its copy, and where it opens.
 * OwnerNotice and BranchNotice extend this with named constructors for each event.
 *
 * `dedupeKey` marks notices that can be triggered more than once for the same event
 * (gateway webhook retries, reminder re-queues, one alert per item per day);
 * NotificationService skips a notice whose key the user already has.
 */
abstract class AppNotice extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $link = null,
        public readonly ?string $dedupeKey = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return array_filter([
            'kind'       => $this->kind,
            'title'      => $this->title,
            'body'       => $this->body,
            'link'       => $this->link,
            'dedupe_key' => $this->dedupeKey,
        ], fn ($value) => $value !== null);
    }
}
