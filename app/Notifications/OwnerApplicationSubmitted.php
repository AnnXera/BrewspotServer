<?php

namespace App\Notifications;

use App\Models\ApprovalList;
use App\Models\Cafe;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Sent to every admin when a cafe owner submits their registration. A rejected
 * owner sending a corrected application gets its own kind and wording, so admins
 * can tell a resubmission apart from a brand-new applicant.
 */
class OwnerApplicationSubmitted extends Notification
{
    /**
     * @param ApprovalList|null $previousRejection The rejection this application corrects, when it is a resubmission.
     */
    public function __construct(
        private readonly User $owner,
        private readonly Cafe $cafe,
        private readonly ApprovalList $approval,
        private readonly ?ApprovalList $previousRejection = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $ownerName = trim("{$this->owner->firstname} {$this->owner->lastname}");

        if ($this->previousRejection) {
            $attempt = ApprovalList::where('user_id', $this->owner->user_id)->count();
            $reason  = $this->previousRejection->reason
                ? ' Previously rejected for: "' . Str::limit($this->previousRejection->reason, 80) . '"'
                : '';

            return [
                'kind'          => 'owner_resubmission',
                'title'         => 'Application resubmitted',
                'body'          => "{$ownerName} corrected their application for {$this->cafe->cafe_name} (attempt {$attempt}) and is waiting for review.{$reason}",
                'approval_uuid' => $this->approval->uuid,
                'owner_uuid'    => $this->owner->uuid,
            ];
        }

        return [
            'kind'          => 'owner_application',
            'title'         => 'New owner application',
            'body'          => "{$ownerName} registered {$this->cafe->cafe_name} and is waiting for approval.",
            'approval_uuid' => $this->approval->uuid,
            'owner_uuid'    => $this->owner->uuid,
        ];
    }
}
