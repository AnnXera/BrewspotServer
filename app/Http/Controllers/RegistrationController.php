<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $service
    ) {}

    /**
     * POST /api/auth/register/{user}
     * {user} resolves via UUID through getRouteKeyName() on the User model.
     */
    public function register(RegisterRequest $request, User $user): JsonResponse
    {
        $result = $this->service->register($user, $request->validated());

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}