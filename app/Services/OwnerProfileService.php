<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Http\Resources\CafeResource;
use App\Http\Resources\CafeBranchResource;
use App\Models\User;
use App\Repository\OwnerProfileRepository;
use Illuminate\Support\Facades\Log;

class OwnerProfileService
{
    public function __construct(
        private readonly OwnerProfileRepository $repo
    ) {}

    public function getProfile(User $owner): array
    {
        $owner = $this->repo->findOwnerWithRole($owner->user_id);

        return [
            'success' => true,
            'user'    => new UserResource($owner),
        ];
    }

    public function getCafes(User $owner): array
    {
        $cafes = $this->repo->findCafesByOwner($owner->user_id);

        return [
            'success' => true,
            'cafes'   => CafeResource::collection($cafes),
        ];
    }

    public function getBranches(User $owner): array
    {
        $branches = $this->repo->findBranchesByOwner($owner->user_id);

        return [
            'success'  => true,
            'branches' => CafeBranchResource::collection($branches),
        ];
    }

    public function getBranch(User $owner, string $branchUuid): array
    {
        $branch = $this->repo->findBranchByUuid($owner->user_id, $branchUuid);

        if (! $branch) {
            Log::channel('owner')->warning('Branch not found or not owned by this user.', [
                'owner_uuid'  => $owner->uuid,
                'branch_uuid' => $branchUuid,
            ]);

            return [
                'success' => false,
                'message' => 'Branch not found.',
            ];
        }

        return [
            'success' => true,
            'branch'  => new CafeBranchResource($branch),
        ];
    }
}