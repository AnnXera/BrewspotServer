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

    /** `data` is a JSON text column, so match the encoded key/value pair. */
    public function hasDedupeKey(User $user, string $key): bool
    {
        $needle = substr(json_encode(['dedupe_key' => $key]), 1, -1);

        return $user->notifications()->where('data', 'like', '%' . addcslashes($needle, '%_\\') . '%')->exists();
    }

    public function markAllReadFor(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }
}
