<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetupPasswordRequest;
use App\Services\PasswordSetupService;
use Illuminate\Http\JsonResponse;

class PasswordSetupController extends Controller
{
    public function __construct(
        private readonly PasswordSetupService $service
    ) {}

    /**
     * POST /api/auth/setup-password/{uuid}
     */
    public function setup(SetupPasswordRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->setupPassword($uuid, $request->validated('password'));

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}