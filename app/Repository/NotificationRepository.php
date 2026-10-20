<?php

namespace App\Repository;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;

class NotificationRepository
{
    /** Active admin accounts, the recipients of approval notifications. */
    public function activeAdmins(): Collection
    {
        return User::whereHas('role', fn ($q) => $q->where('role_name', 'Admin'))
            ->where('status', 'active')
            ->get();
    }

    public function latestFor(User $user, int $limit): Collection
    {
        return $user->notifications()->latest()->limit($limit)->get();
    }

    public function unreadCountFor(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function findFor(User $user, string $id): ?DatabaseNotification
    {
        return $user->notifications()->whereKey($id)->first();
    }

    public function hasDedupeKey(User $user, string $key): bool
    {
        return $this->whereDedupeKey($user, $key)->exists();
    }

    public function findByDedupeKey(User $user, string $key): ?DatabaseNotification
    {
        return $this->whereDedupeKey($user, $key)->latest()->first();
    }

    /** Rewrite a notice in place: new copy, unread again, and back at the top of the list. */
    public function refresh(DatabaseNotification $notification, array $data): void
    {
        $notification->forceFill([
            'data'       => $data,
            'read_at'    => null,
            'created_at' => now(),
        ])->save();
    }

    /** `data` is a JSON text column, so match the encoded key/value pair. */
    private function whereDedupeKey(User $user, string $key)
    {
        $needle = substr(json_encode(['dedupe_key' => $key]), 1, -1);

        return $user->notifications()->where('data', 'like', '%' . addcslashes($needle, '%_\\') . '%');
    }

    /** Users with an active Manager assignment at the branch (who run it day to day). */
    public function activeManagersAtBranch(int $branchId): Collection
    {
        return User::where('status', '!=', 'inactive')
            ->whereHas('role', fn ($q) => $q->where('role_name', 'Manager'))
            ->whereHas('staffAssignments', fn ($q) => $q->where('branch_id', $branchId)->active())
            ->get();
    }

    public function markAllReadFor(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
