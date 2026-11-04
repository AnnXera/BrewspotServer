<?php

namespace App\Services;

use App\Models\User;
use App\Repository\DocumentRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves which stored file a document request may see.
 *
 * Results carry an `http` key on failure; on success they hold the `disk` and
 * `path` for the controller to stream.
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentRepository $repo
    ) {}

    public function userDocument(User $viewer, int $id, string $side): array
    {
        $document = $this->repo->findUserDocument($id);

        if (! $document) {
            return $this->notFound();
        }

        if ($denied = $this->deny($viewer, $document->user_id)) {
            return $denied;
        }

        $path = $side === 'back' ? $document->file_back : $document->file;

        return $path
            ? $this->privateFile($path)
            : ['success' => false, 'http' => 404, 'message' => 'Document file not found.'];
    }

    public function cafeDocument(User $viewer, int $id): array
    {
        $document = $this->repo->findCafeDocument($id);

        if (! $document || ! $document->cafe) {
            return $this->notFound();
        }

        return $this->deny($viewer, $document->cafe->user_id) ?? $this->privateFile($document->file);
    }

    public function branchDocument(User $viewer, int $id): array
    {
        $document = $this->repo->findBranchDocument($id);

        if (! $document || ! $document->branch || ! $document->branch->cafe) {
            return $this->notFound();
        }

        return $this->deny($viewer, $document->branch->cafe->user_id) ?? $this->privateFile($document->file);
    }

    /** Public: a branch's display picture. */
    public function branchPicture(string $uuid): array
    {
        $branch = $this->repo->findBranchByUuid($uuid);

        if (! $branch) {
            return $this->notFound();
        }

        if (! $branch->cafe_picture || ! Storage::disk('public')->exists($branch->cafe_picture)) {
            return ['success' => false, 'http' => 404, 'message' => 'Picture not found.'];
        }

        return ['success' => true, 'disk' => 'public', 'path' => $branch->cafe_picture];
    }

    /**
     * Only the document's owner or an Admin may view it. Null when allowed.
     */
    private function deny(User $viewer, int $ownerUserId): ?array
    {
        if ($viewer->role->role_name === 'Admin' || $viewer->user_id === $ownerUserId) {
            return null;
        }

        Log::channel('admin')->warning('Unauthorized document access attempt.', [
            'requesting_user_uuid' => $viewer->uuid,
            'target_owner_user_id' => $ownerUserId,
        ]);

        return ['success' => false, 'http' => 403, 'message' => 'You do not have permission to view this document.'];
    }

    private function privateFile(string $path): array
    {
        if (! Storage::disk('local')->exists($path)) {
            return $this->notFound();
        }

        return ['success' => true, 'disk' => 'local', 'path' => $path];
    }

    private function notFound(): array
    {
        return ['success' => false, 'http' => 404, 'message' => 'Document not found.'];
    }
}
