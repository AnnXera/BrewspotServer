<?php

namespace App\Notifications;

use App\Models\CafeUpdateRequest;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to every admin when an owner asks to change their cafe's registered name
 * or a branch's registered address.
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
        $address = $this->request->isAddressChange();
        $label   = $address ? 'Branch address change' : 'Cafe name change';
        $branch  = $this->request->branch?->branch_name ?? 'A branch';

        return [
            'kind'         => 'cafe_update',
            'title'        => $this->resubmitted ? "{$label} resubmitted" : "{$label} to review",
            'body'         => $address
                ? "{$branch} wants to move to {$this->request->new_address}."
                : "{$this->request->old_name} wants to be renamed to {$this->request->new_name}.",
            'request_uuid' => $this->request->uuid,
            'owner_uuid'   => $this->owner->uuid,
            'link'         => "/admin/approvals?type=cafe_update&request={$this->request->uuid}",
        ];
    }
}
