<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserProfileController extends Controller
{
    /**
     * GET /api/user/profile
     * Fetch authenticated user details, workplace/branch info, and avatar.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('role');

        $workplace = null;
        if ($user->isEmployee()) {
            $assignment = $user->activeStaffAssignments()->with('branch.cafe')->first();
            if ($assignment && $assignment->branch) {
                $workplace = [
                    'cafe_name'      => $assignment->branch->cafe?->cafe_name ?? 'BrewSpot Cafe',
                    'branch_name'    => $assignment->branch->branch_name,
                    'branch_address' => $assignment->branch->address,
                    'branch_type'    => $assignment->branch->branch_type ?? 'Branch',
                    'position'       => $user->roleName(),
                ];
            }
        } elseif ($user->isOwner()) {
            $cafe = $user->cafes()->with('branches')->first();
            if ($cafe) {
                $workplace = [
                    'cafe_name'      => $cafe->cafe_name,
                    'branch_name'    => $cafe->branches->first()?->branch_name ?? 'Main Branch',
                    'branch_address' => $cafe->branches->first()?->address ?? null,
                    'branch_type'    => 'Headquarters',
                    'position'       => 'Cafe Owner',
                ];
            }
        } elseif ($user->roleName() === 'Admin') {
            $workplace = [
                'cafe_name'      => 'BrewSpot Platform',
                'branch_name'    => 'System Administration',
                'branch_address' => 'BrewSpot HQ',
                'branch_type'    => 'Administration',
                'position'       => 'Administrator',
            ];
        }

        return response()->json([
            'success'   => true,
            'user'      => new UserResource($user),
            'workplace' => $workplace,
        ]);
    }

    /**
     * PUT /api/user/profile
     * Update editable personal details (name, phone, address).
     */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'firstname'    => 'required|string|max:100',
            'middlename'   => 'nullable|string|max:100',
            'lastname'     => 'required|string|max:100',
            'phone_number' => 'nullable|string|max:30',
            'address'      => 'nullable|string|max:255',
        ]);

        $user->update([
            'firstname'    => trim($validated['firstname']),
            'middlename'   => !empty($validated['middlename']) ? trim($validated['middlename']) : null,
            'lastname'     => trim($validated['lastname']),
            'phone_number' => !empty($validated['phone_number']) ? trim($validated['phone_number']) : null,
            'address'      => !empty($validated['address']) ? trim($validated['address']) : null,
        ]);

        $user->loadMissing('role');

        return response()->json([
            'success' => true,
            'message' => 'Profile details updated successfully.',
            'user'    => new UserResource($user->fresh(['role'])),
        ]);
    }

    /**
     * POST /api/user/profile-photo
     * Upload / replace profile picture.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,jpg,webp|max:3072',
        ]);

        /** @var User $user */
        $user = $request->user();
        $file = $request->file('photo');

        // Delete any existing photo for this user
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        foreach ($extensions as $ext) {
            $oldPath = "user_photos/{$user->uuid}.{$ext}";
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = "{$user->uuid}.{$extension}";
        Storage::disk('public')->putFileAs('user_photos', $file, $filename);

        $freshUser = $user->fresh();

        return response()->json([
            'success'   => true,
            'message'   => 'Profile photo updated successfully.',
            'photo_url' => $freshUser->photo_url,
        ]);
    }

    /**
     * DELETE /api/user/profile-photo
     * Remove custom profile photo and revert to initials.
     */
    public function deletePhoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        foreach ($extensions as $ext) {
            $oldPath = "user_photos/{$user->uuid}.{$ext}";
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile photo removed.',
        ]);
    }

    /**
     * PUT /api/user/change-password
     * Change authenticated user password with current password verification.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->input('current_password'), $user->password_hash)) {
            return response()->json([
                'success' => false,
                'message' => 'The current password you provided is incorrect.',
                'errors'  => [
                    'current_password' => ['The current password you provided is incorrect.'],
                ],
            ], 422);
        }

        $user->password_hash = Hash::make($request->input('password'));
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Your password has been changed successfully.',
        ]);
    }

    /**
     * GET /api/user-photo/{uuid}
     * Public streamed response for user photo.
     */
    public function photo(string $uuid): StreamedResponse
    {
        $extensions = ['jpg', 'jpeg', 'png', 'webp'];
        foreach ($extensions as $ext) {
            $path = "user_photos/{$uuid}.{$ext}";
            if (Storage::disk('public')->exists($path)) {
                return Storage::disk('public')->response($path);
            }
        }

        abort(404, 'Photo not found.');
    }
}
