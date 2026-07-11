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
     * POST /api/auth/register/{uuid}
     */
    public function register(RegisterRequest $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired registration link. No account found for this UUID.',
            ], 404);
        }

        $result = $this->service->register($user, $request->validated());

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}