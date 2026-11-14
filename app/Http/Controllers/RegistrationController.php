<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResubmitApplicationRequest;
use App\Http\Requests\ValidateRegistrationStepRequest;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $service
    ) {}

    /**
     * POST /api/auth/validate-registration-step/{uuid?}
     * Validation runs in the form request; reaching here means the step passed.
     */
    public function validateStep(ValidateRegistrationStepRequest $request, ?string $uuid = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Validation passed.',
        ]);
    }

    /**
     * POST /api/auth/register/{uuid}
     */
    public function register(RegisterRequest $request, string $uuid): JsonResponse
    {
        return $this->respond($this->service->registerByUuid($uuid, $request->validated()));
    }

    /**
     * POST /api/auth/application/{uuid}/resubmit
     */
    public function resubmit(ResubmitApplicationRequest $request, string $uuid): JsonResponse
    {
        return $this->respond($this->service->resubmitByUuid($uuid, $request->validated()));
    }

    /**
     * GET /api/auth/register/{uuid}/previous-application
     */
    public function previousApplication(string $uuid): JsonResponse
    {
        return $this->respond($this->service->getPreviousApplication($uuid));
    }

    /**
     * GET /api/auth/application/{uuid}
     */
    public function showApplication(string $uuid): JsonResponse
    {
        $result = $this->service->getApplicationDetails($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }
}
