<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOwnerStatusRequest;
use App\Services\OwnerManagementService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

class OwnerManagementController extends Controller
{
    public function __construct(
        private readonly OwnerManagementService $service
    ) {}

    /**
     * GET /api/admin/owners
     */
    public function index(Request $request): JsonResponse
    {
        $owners = $this->service->listOwners($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'owners'  => $owners,
        ]);
    }

    /**
     * GET /api/admin/owners/{uuid}
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $result = $this->service->getOwnerDetails($uuid);

            return response()->json($result, 200);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => "The owner profile with UUID '{$uuid}' does not exist or is not assigned as an owner."
            ], 404);
        }
    }

    /**
     * PATCH /api/admin/owners/{uuid}/status
     */
    public function updateStatus(UpdateOwnerStatusRequest $request, string $uuid): JsonResponse
    {
        $reviewerId = $request->user()->user_id;

        try {
            $result = $this->service->updateStatus($uuid, $request->validated('status'), $reviewerId);
            
            return response()->json($result, $result['success'] ? 200 : 422);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => "Owner status update failed. No owner found with UUID: {$uuid}."
            ], 404);
        }
    }

    /**
     * GET /api/admin/approvals
     * Optional ?status= filter (pending_approval, active, rejected, inactive)
     */
    public function approvals(Request $request): JsonResponse
    {
        $approvals = $this->service->listApprovals(
            $request->input('per_page', 15),
            $request->input('status')
        );

        return response()->json([
            'success'   => true,
            'approvals' => $approvals,
        ]);
    }
}