<?php

namespace App\Services;

use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Notifications\AppNotice;
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

    public function notifyOwner(?User $owner, OwnerNotice $notice): void
    {
        $this->notifyUser($owner, $notice);
    }

    /**
     * Notify one user. Never throws, for the same reason as notifyAdmins(), and skips
     * the notice when the user already has one with the same dedupe key.
     */
    public function notifyUser(?User $user, AppNotice $notice): void
    {
        if (! $user) {
            return;
        }

        try {
            if ($notice->dedupeKey && $this->repo->hasDedupeKey($user, $notice->dedupeKey)) {
                return;
            }

            $user->notify($notice);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify user.', [
                'user_uuid' => $user->uuid,
                'kind'      => $notice->kind,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** @param iterable<User> $users */
    public function notifyUsers(iterable $users, AppNotice $notice): void
    {
        foreach ($users as $user) {
            $this->notifyUser($user, $notice);
        }
    }

    /**
     * For running summaries (one per dedupe key): rewrite the user's existing notice with
     * the new copy, mark it unread and move it to the top; create it if there is none.
     *
     * @param iterable<User> $users
     */
    public function refreshForUsers(iterable $users, AppNotice $notice): void
    {
        foreach ($users as $user) {
            try {
                $existing = $notice->dedupeKey ? $this->repo->findByDedupeKey($user, $notice->dedupeKey) : null;

                if ($existing) {
                    $this->repo->refresh($existing, $notice->toArray($user));
                } else {
                    $user->notify($notice);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to refresh notification.', [
                    'user_uuid' => $user->uuid,
                    'kind'      => $notice->kind,
                    'error'     => $e->getMessage(),
                ]);
            }
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
