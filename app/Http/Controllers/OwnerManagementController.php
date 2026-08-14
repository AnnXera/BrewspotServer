<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOwnerStatusRequest;
use App\Http\Requests\UpdateBranchStatusRequest;

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
     * Optional ?search= ?status= ?date= ?per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $owners = $this->service->listOwners(
            $request->input('per_page', 15),
            $request->input('search'),
            $request->input('status'),
            $request->input('date')
        );

        return response()->json([
            'success' => true,
            'owners'  => $owners,
        ]);
    }

    /**
     * GET /api/admin/owners/stats
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'stats'   => $this->service->getStats(),
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
            $result = $this->service->updateStatus(
                $uuid,
                $request->validated('status'),
                $reviewerId,
                $request->validated('reason')
            );

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
     * Optional ?search= ?status= ?type= ?per_page=
     */
    public function approvals(Request $request): JsonResponse
    {
        $approvals = $this->service->listApprovals(
            $request->input('per_page', 15),
            $request->input('status'),
            $request->input('type'),
            $request->input('search')
        );

        return response()->json([
            'success'   => true,
            'approvals' => $approvals,
        ]);
    }

    /**
     * GET /api/admin/approvals/stats
     * Optional ?type= filter, same as above
     */
    public function approvalStats(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'stats'   => $this->service->getApprovalStats($request->input('type')),
        ]);
    }

    /**
     * PATCH /api/admin/branches/{uuid}/status
     */
    public function updateBranchStatus(UpdateBranchStatusRequest $request, string $uuid): JsonResponse
    {
        $reviewerId = $request->user()->user_id;

        $result = $this->service->updateBranchStatus(
            $uuid,
            $request->validated('status'),
            $reviewerId,
            $request->validated('reason')
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * GET /api/admin/owners/{uuid}/application-history
     */
    public function applicationHistory(string $uuid): JsonResponse
    {
        try {
            $result = $this->service->getApplicationHistory($uuid);

            return response()->json($result, 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => "No owner found with UUID: {$uuid}.",
            ], 404);
        }
    }

    /**
     * GET /api/admin/approvals/{uuid}/snapshot
     */
    public function approvalSnapshot(string $uuid): JsonResponse
    {
        $result = $this->service->getApprovalSnapshot($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }
}