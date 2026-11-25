<?php

namespace App\Notifications;

use App\Models\CafeUpdateRequest;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to every admin when an owner asks to change their cafe's registered name.
 */
class CafeUpdateSubmitted extends Notification
{
    public function __construct(
        private readonly User $owner,
        private readonly CafeUpdateRequest $request,
        private readonly bool $resubmitted = false // sent again after an earlier request was declined
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'         => 'cafe_update',
            'title'        => $this->resubmitted ? 'Cafe name change resubmitted' : 'Cafe name change to review',
            'body'         => "{$this->request->old_name} wants to be renamed to {$this->request->new_name}.",
            'request_uuid' => $this->request->uuid,
            'owner_uuid'   => $this->owner->uuid,
            'link'         => "/admin/approvals?type=cafe_update&request={$this->request->uuid}",
        ];
    }
}
