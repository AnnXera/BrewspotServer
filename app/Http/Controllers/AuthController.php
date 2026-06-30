<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\VerifyLoginCodeRequest;
use App\Http\Requests\ResendCodeRequest;

use App\Services\AuthService;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $service
    ) {}

    /**
     * POST /api/auth/login
     * Step 1 — validate credentials, send 2FA code.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->service->login(
            $request->validated('email'),
            $request->validated('password')
        );

        return response()->json($result, $result['success'] ? 200 : 401);
    }

    /**
     * POST /api/auth/verify-login-code
     * Step 2 — verify 2FA code, issue token.
     */
    public function verifyLoginCode(VerifyLoginCodeRequest $request): JsonResponse
    {
        $result = $this->service->verifyLoginCode(
            $request->validated('email'),
            $request->validated('code')
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function resendLoginCode(ResendCodeRequest $request): JsonResponse
    {
        $result = $this->service->resendLoginCode($request->validated('email'));

        return response()->json($result, $result['success'] ? 200 : 429);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $result = $this->service->logout($request->user());

        return response()->json($result, 200);
    }
}