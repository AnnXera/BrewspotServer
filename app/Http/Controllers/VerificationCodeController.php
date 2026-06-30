<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendVerificationCodeRequest;
use App\Http\Requests\ResendCodeRequest;
use App\Http\Requests\VerifyCodeRequest;
use App\Services\VerificationCodeService;
use Illuminate\Http\JsonResponse;

class VerificationCodeController extends Controller
{
    public function __construct(
        private readonly VerificationCodeService $service
    ) {}

    public function sendCode(SendVerificationCodeRequest $request): JsonResponse
    {
        $result = $this->service->sendCode($request->validated('email'));

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function resendCode(ResendCodeRequest $request): JsonResponse
    {
        $result = $this->service->resendVerificationCode($request->validated('email'));

        return response()->json($result, $result['success'] ? 200 : 429);
    }

    public function verifyCode(VerifyCodeRequest $request): JsonResponse
    {
        $result = $this->service->verifyCode(
            $request->validated('email'),
            $request->validated('code')
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}