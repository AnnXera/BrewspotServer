<?php

namespace App\Notifications;

use App\Models\ApprovalList;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Sent to every admin when an active owner adds a branch that needs approval.
 */
class BranchSubmittedForApproval extends Notification
{
    public function __construct(
        private readonly User $owner,
        private readonly Cafe $cafe,
        private readonly CafeBranch $branch,
        private readonly ApprovalList $approval
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'          => 'branch_submission',
            'title'         => 'New branch to review',
            'body'          => "{$this->cafe->cafe_name} submitted {$this->branch->branch_name} for approval.",
            'approval_uuid' => $this->approval->uuid,
            'owner_uuid'    => $this->owner->uuid,
        ];
    }
}
