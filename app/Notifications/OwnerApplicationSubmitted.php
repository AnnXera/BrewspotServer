<?php

namespace App\Notifications;

use App\Models\ApprovalList;
use App\Models\Cafe;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to every admin when a new cafe owner submits their registration.
 */
class OwnerApplicationSubmitted extends Notification
{
    public function __construct(
        private readonly User $owner,
        private readonly Cafe $cafe,
        private readonly ApprovalList $approval
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $ownerName = trim("{$this->owner->firstname} {$this->owner->lastname}");

        return [
            'kind'          => 'owner_application',
            'title'         => 'New owner application',
            'body'          => "{$ownerName} registered {$this->cafe->cafe_name} and is waiting for approval.",
            'approval_uuid' => $this->approval->uuid,
            'owner_uuid'    => $this->owner->uuid,
        ];
    }
}
