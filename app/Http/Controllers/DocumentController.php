<?php

namespace App\Http\Controllers;

use App\Models\CafeBranch;
use App\Models\UserDocument;
use App\Models\CafeDocument;
use App\Models\BranchDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /**
     * GET /api/documents/user/{userDocId}
     */
    public function userDocument(Request $request, int $userDocId): StreamedResponse
    {
        $document = UserDocument::findOrFail($userDocId);

        $this->authorizeAccess($request, $document->user_id);

        return $this->streamFile($document->file);
    }

    /**
     * GET /api/documents/cafe/{cafeDocId}
     */
    public function cafeDocument(Request $request, int $cafeDocId): StreamedResponse
    {
        $document = CafeDocument::with('cafe')->findOrFail($cafeDocId);

        $this->authorizeAccess($request, $document->cafe->user_id);

        return $this->streamFile($document->file);
    }

    /**
     * GET /api/documents/branch/{branchDocId}
     */
    public function branchDocument(Request $request, int $branchDocId): StreamedResponse
    {
        $document = BranchDocument::with('branch.cafe')->findOrFail($branchDocId);

        $this->authorizeAccess($request, $document->branch->cafe->user_id);

        return $this->streamFile($document->file);
    }

    /**
     * GET /api/branch-picture/{uuid}
     * Public — serves the branch's display picture with correct Content-Type.
     */
    public function branchPicture(string $uuid): StreamedResponse
    {
        $branch = CafeBranch::where('uuid', $uuid)->firstOrFail();

        if (! $branch->cafe_picture || ! Storage::disk('public')->exists($branch->cafe_picture)) {
            abort(404, 'Picture not found.');
        }

        return Storage::disk('public')->response($branch->cafe_picture);
    }

    /**
     * Only the document's owner or an Admin may view it.
     */
    private function authorizeAccess(Request $request, int $ownerUserId): void
    {
        $user = $request->user();

        $isAdmin          = $user->role->role_name === 'Admin';
        $isOwnerOfDocument = $user->user_id === $ownerUserId;

        if (! $isAdmin && ! $isOwnerOfDocument) {
            Log::channel('admin')->warning('Unauthorized document access attempt.', [
                'requesting_user_uuid' => $user->uuid,
                'target_owner_user_id' => $ownerUserId,
            ]);

            abort(403, 'You do not have permission to view this document.');
        }
    }

    private function streamFile(string $path): StreamedResponse
    {
        if (! Storage::disk('local')->exists($path)) {
            abort(404, 'Document not found.');
        }

        return Storage::disk('local')->response($path);
    }
}