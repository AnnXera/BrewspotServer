<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repository\UserProfileRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserProfileService
{
    private const PHOTO_DIR = 'user_photos';

    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly UserProfileRepository $repo
    ) {}

    public function show(User $user): array
    {
        $user->loadMissing('role');

        return [
            'success'   => true,
            'user'      => new UserResource($user),
            'workplace' => $this->workplace($user),
        ];
    }

    public function updateDetails(User $user, array $validated): array
    {
        $optional = fn (?string $value) => ! empty($value) ? trim($value) : null;

        $fresh = $this->repo->updateDetails($user, [
            'firstname'    => trim($validated['firstname']),
            'middlename'   => $optional($validated['middlename'] ?? null),
            'lastname'     => trim($validated['lastname']),
            'phone_number' => $optional($validated['phone_number'] ?? null),
            'address'      => $optional($validated['address'] ?? null),
        ]);

        return [
            'success' => true,
            'message' => 'Profile details updated successfully.',
            'user'    => new UserResource($fresh),
        ];
    }

    public function replacePhoto(User $user, UploadedFile $file): array
    {
        $this->deleteStoredPhotos($user->uuid);

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        Storage::disk('public')->putFileAs(self::PHOTO_DIR, $file, "{$user->uuid}.{$extension}");

        return [
            'success'   => true,
            'message'   => 'Profile photo updated successfully.',
            'photo_url' => $user->fresh()->photo_url,
        ];
    }

    public function deletePhoto(User $user): array
    {
        $this->deleteStoredPhotos($user->uuid);

        return [
            'success' => true,
            'message' => 'Profile photo removed.',
        ];
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): array
    {
        if (! Hash::check($currentPassword, $user->password_hash)) {
            $message = 'The current password you provided is incorrect.';

            return [
                'success' => false,
                'message' => $message,
                'errors'  => ['current_password' => [$message]],
            ];
        }

        $this->repo->updatePasswordHash($user, Hash::make($newPassword));

        return [
            'success' => true,
            'message' => 'Your password has been changed successfully.',
        ];
    }

    /** Storage path of the user's photo, or null when they have none. */
    public function photoPath(string $uuid): ?string
    {
        foreach (self::PHOTO_EXTENSIONS as $ext) {
            $path = self::PHOTO_DIR . "/{$uuid}.{$ext}";
            if (Storage::disk('public')->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function deleteStoredPhotos(string $uuid): void
    {
        foreach (self::PHOTO_EXTENSIONS as $ext) {
            $path = self::PHOTO_DIR . "/{$uuid}.{$ext}";
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function workplace(User $user): ?array
    {
        if ($user->isEmployee()) {
            $assignment = $this->repo->findActiveAssignmentWithBranch($user);

            if (! $assignment || ! $assignment->branch) {
                return null;
            }

            return [
                'cafe_name'      => $assignment->branch->cafe?->cafe_name ?? 'BrewSpot Cafe',
                'branch_name'    => $assignment->branch->branch_name,
                'branch_address' => $assignment->branch->address,
                'branch_type'    => $assignment->branch->branch_type ?? 'Branch',
                'position'       => $user->roleName(),
            ];
        }

        if ($user->isOwner()) {
            $cafe = $this->repo->findOwnerCafeWithBranches($user);

            if (! $cafe) {
                return null;
            }

            return [
                'cafe_name'      => $cafe->cafe_name,
                'branch_name'    => $cafe->branches->first()?->branch_name ?? 'Main Branch',
                'branch_address' => $cafe->branches->first()?->address ?? null,
                'branch_type'    => 'Headquarters',
                'position'       => 'Cafe Owner',
            ];
        }

        if ($user->roleName() === 'Admin') {
            return [
                'cafe_name'      => 'BrewSpot Platform',
                'branch_name'    => 'System Administration',
                'branch_address' => 'BrewSpot HQ',
                'branch_type'    => 'Administration',
                'position'       => 'Administrator',
            ];
        }

        return null;
    }
}
