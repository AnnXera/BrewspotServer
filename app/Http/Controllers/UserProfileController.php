<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeOwnPinRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Http\Requests\UploadProfilePhotoRequest;
use App\Services\StaffPinService;
use App\Services\UserProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserProfileController extends Controller
{
    public function __construct(
        private readonly UserProfileService $service
    ) {}

    /**
     * GET /api/user/profile
     * Fetch authenticated user details, workplace/branch info, and avatar.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->service->show($request->user()));
    }

    /**
     * PUT /api/user/profile
     * Update editable personal details (name, phone, address).
     */
    public function update(UpdateUserProfileRequest $request): JsonResponse
    {
        return response()->json($this->service->updateDetails($request->user(), $request->validated()));
    }

    /**
     * POST /api/user/profile-photo
     * Upload / replace profile picture.
     */
    public function uploadPhoto(UploadProfilePhotoRequest $request): JsonResponse
    {
        return response()->json($this->service->replacePhoto($request->user(), $request->file('photo')));
    }

    /**
     * DELETE /api/user/profile-photo
     * Remove custom profile photo and revert to initials.
     */
    public function deletePhoto(Request $request): JsonResponse
    {
        return response()->json($this->service->deletePhoto($request->user()));
    }

    /**
     * PUT /api/user/change-password
     * Change authenticated user password with current password verification.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        return $this->respond($this->service->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password')
        ));
    }

    /**
     * PUT /api/user/pin
     * Owner or manager sets their own register PIN (approves voids/refunds), confirmed with their password.
     */
    public function updatePin(ChangeOwnPinRequest $request, StaffPinService $pins): JsonResponse
    {
        return $this->respond($pins->changeOwnPin(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('pin')
        ));
    }

    /**
     * GET /api/user-photo/{uuid}
     * Public streamed response for user photo.
     */
    public function photo(string $uuid): StreamedResponse
    {
        $path = $this->service->photoPath($uuid);

        abort_if($path === null, 404, 'Photo not found.');

        return Storage::disk('public')->response($path);
    }
}
