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
     * GET /api/auth/setup-password/{uuid}
     */
    public function show(string $uuid): JsonResponse
    {
        $result = $this->service->checkSetupStatus($uuid);

        $status = 200;
        if (! $result['success'] && empty($result['already_active'])) {
            $status = 404;
        }

        return response()->json($result, $status);
    }

    /**
     * POST /api/auth/setup-password/{uuid}
     */
    public function setup(SetupPasswordRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->setupPassword($uuid, $request->validated('password'));

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}