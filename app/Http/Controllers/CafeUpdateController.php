<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitBranchAddressChangeRequest;
use App\Http\Requests\SubmitCafeNameChangeRequest;
use App\Http\Requests\UpdateBranchStatusRequest;
use App\Services\CafeUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cafe name and branch address changes: owners request them, admins review them
 * on the Approval Status page (Cafe Updates tab).
 */
class CafeUpdateController extends Controller
{
    public function __construct(
        private readonly CafeUpdateService $service
    ) {}

    // ── Owner ──

    /** GET /api/owner/cafe-name-change */
    public function overview(Request $request): JsonResponse
    {
        $result = $this->service->overview($request->user());

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /** POST /api/owner/cafe-name-change */
    public function submit(SubmitCafeNameChangeRequest $request): JsonResponse
    {
        $result = $this->service->submit($request->user(), [
            'new_name'  => $request->validated('new_name'),
            'documents' => $request->validated('documents'),
        ]);

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    /** DELETE /api/owner/cafe-name-change/{uuid} */
    public function withdraw(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->withdraw($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** GET /api/owner/branches/{uuid}/address-change */
    public function addressOverview(Request $request, string $uuid): JsonResponse
    {
        $result = $this->service->addressOverview($request->user(), $uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /** POST /api/owner/branches/{uuid}/address-change */
    public function submitAddress(SubmitBranchAddressChangeRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->submitAddressChange($request->user(), $uuid, [
            'new_address'   => $request->validated('new_address'),
            'file'          => $request->file('file'),
            'registered_at' => $request->validated('registered_at'),
        ]);

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    /** DELETE /api/owner/branches/{uuid}/address-change/{requestUuid} */
    public function withdrawAddress(Request $request, string $uuid, string $requestUuid): JsonResponse
    {
        $result = $this->service->withdraw($request->user(), $requestUuid);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // ── Admin ──

    /** GET /api/admin/cafe-updates  Optional ?status= ?search= ?per_page= */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success'  => true,
            'requests' => $this->service->list(
                (int) $request->input('per_page', 15),
                $request->input('status'),
                $request->input('search')
            ),
        ]);
    }

    /** GET /api/admin/cafe-updates/stats */
    public function stats(): JsonResponse
    {
        return response()->json(['success' => true, 'stats' => $this->service->stats()]);
    }

    /** GET /api/admin/cafe-updates/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        $result = $this->service->show($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * PATCH /api/admin/cafe-updates/{uuid}/status
     * Same body as a branch review: status (approved|rejected), reason, allow_resubmit.
     */
    public function review(UpdateBranchStatusRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->review(
            $uuid,
            $request->validated('status'),
            $request->user(),
            $request->validated('reason'),
            $request->boolean('allow_resubmit', true)
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
