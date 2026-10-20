<?php

namespace App\Services;

use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Notifications\OwnerNotice;
use App\Repository\NotificationRepository;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class NotificationService
{
    /** How many recent notifications the bell shows. */
    private const LIST_LIMIT = 30;

    public function __construct(
        private readonly NotificationRepository $repo
    ) {}

    /**
     * Notify every active admin. Never throws: a failed notification must not
     * undo the registration or branch submission that triggered it.
     */
    public function notifyAdmins(Notification $notification): void
    {
        try {
            $admins = $this->repo->activeAdmins();

            if ($admins->isNotEmpty()) {
                NotificationFacade::send($admins, $notification);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins.', [
                'notification' => $notification::class,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify one owner. Never throws, for the same reason as notifyAdmins(), and skips
     * the notice when the owner already has one with the same dedupe key.
     */
    public function notifyOwner(?User $owner, OwnerNotice $notice): void
    {
        if (! $owner) {
            return;
        }

        try {
            if ($notice->dedupeKey && $this->repo->hasDedupeKey($owner, $notice->dedupeKey)) {
                return;
            }

            $owner->notify($notice);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify owner.', [
                'owner_uuid' => $owner->uuid,
                'kind'       => $notice->kind,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    public function listFor(User $user): array
    {
        return [
            'success'       => true,
            'notifications' => NotificationResource::collection($this->repo->latestFor($user, self::LIST_LIMIT)),
            'unread_count'  => $this->repo->unreadCountFor($user),
        ];
    }

    public function markRead(User $user, string $id): array
    {
        $notification = $this->repo->findFor($user, $id);

        if (! $notification) {
            return ['success' => false, 'message' => 'Notification not found.'];
        }

        $notification->markAsRead();

        return [
            'success'      => true,
            'unread_count' => $this->repo->unreadCountFor($user),
        ];
    }

    public function markAllRead(User $user): array
    {
        $this->repo->markAllReadFor($user);

        return ['success' => true, 'unread_count' => 0];
    }
}
