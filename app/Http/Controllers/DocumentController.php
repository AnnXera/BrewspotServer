<?php

namespace App\Http\Controllers;

use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $service
    ) {}

    /**
     * GET /api/documents/user/{userDocId}/{side?}
     */
    public function userDocument(Request $request, int $userDocId, ?string $side = null): StreamedResponse
    {
        return $this->stream($this->service->userDocument(
            $request->user(),
            $userDocId,
            $side ?? $request->query('side', 'front')
        ));
    }

    /**
     * GET /api/documents/cafe/{cafeDocId}
     */
    public function cafeDocument(Request $request, int $cafeDocId): StreamedResponse
    {
        return $this->stream($this->service->cafeDocument($request->user(), $cafeDocId));
    }

    /**
     * GET /api/documents/branch/{branchDocId}
     */
    public function branchDocument(Request $request, int $branchDocId): StreamedResponse
    {
        return $this->stream($this->service->branchDocument($request->user(), $branchDocId));
    }

    /**
     * GET /api/branch-picture/{uuid}
     * Public — serves the branch's display picture with correct Content-Type.
     */
    public function branchPicture(string $uuid): StreamedResponse
    {
        return $this->stream($this->service->branchPicture($uuid));
    }

    private function stream(array $result): StreamedResponse
    {
        abort_unless($result['success'], $result['http'] ?? 404, $result['message'] ?? '');

        return Storage::disk($result['disk'])->response($result['path']);
    }
}
