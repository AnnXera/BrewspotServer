<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Repository\RegistrationRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegistrationService
{
    public function __construct(
        private readonly RegistrationRepository $repo
    ) {}

    public function register(User $user, array $payload): array
    {
        if ($user->status !== 'filling_application') {
            Log::channel('registration')->warning('Registration blocked — invalid status.', [
                'user_uuid' => $user->uuid,
                'status'    => $user->status,
            ]);

            return [
                'success' => false,
                'message' => 'This account is not eligible for registration.',
            ];
        }

        $result = [];

        try {
            DB::transaction(function () use ($user, $payload, &$result) {

                $userFolder = 'users/' . $user->uuid;

                // 1. Update user profile
                $user = $this->repo->updateUserProfile($user, $payload);

                // 2. Store user government ID
                $idFilePath = $this->storeFile(
                    $payload['file'],
                    "{$userFolder}/user_documents"
                );
                $this->repo->createUserDocument(
                    $user->user_id,
                    $idFilePath,
                    $payload['id_type']
                );

                // 3. Create cafe so we have the UUID for the folder path
                $cafe       = $this->repo->createCafe($user->user_id, $payload['cafe_name']);
                $cafeFolder = "{$userFolder}/cafes/{$cafe->uuid}";

                // 4. Store cafe DTI/SEC document
                $dtiSecFilePath = $this->storeFile(
                    $payload['dti_sec_file'],
                    "{$cafeFolder}/cafe_documents"
                );
                $this->repo->createCafeDocument(
                    $cafe->cafe_id,
                    $payload['cafe_doc_type'],
                    $dtiSecFilePath
                );

                // 5. Create branch so we have the UUID for the folder path
                $branch       = $this->repo->createBranch($cafe->cafe_id, $payload);
                $branchFolder = "{$cafeFolder}/branches/{$branch->uuid}";

                // 6. Store cafe picture if provided then update the branch record
                if (isset($payload['cafe_picture'])) {
                    $cafePicturePath = $this->storeFile(
                        $payload['cafe_picture'],
                        "{$branchFolder}/cafe_pictures"
                    );
                    $branch->update(['cafe_picture' => $cafePicturePath]);
                }

                // 7. Store branch documents
                $birFilePath      = $this->storeFile($payload['bir_file'],             "{$branchFolder}/branch_documents");
                $mayorsFilePath   = $this->storeFile($payload['mayors_permit_file'],   "{$branchFolder}/branch_documents");
                $sanitaryFilePath = $this->storeFile($payload['sanitary_permit_file'], "{$branchFolder}/branch_documents");

                $this->repo->createBranchDocument($branch->branch_id, 'BIR',             $birFilePath);
                $this->repo->createBranchDocument($branch->branch_id, 'mayors_permit',   $mayorsFilePath);
                $this->repo->createBranchDocument($branch->branch_id, 'sanitary_permit', $sanitaryFilePath);

                // 8. Create approval entry for admin review
                $this->repo->createApprovalEntry(
                    $user->user_id,
                    $cafe->cafe_id,
                    $branch->branch_id
                );

                Log::channel('registration')->info('Registration submitted successfully.', [
                    'user_uuid'   => $user->uuid,
                    'cafe_uuid'   => $cafe->uuid,
                    'branch_uuid' => $branch->uuid,
                ]);

                $result = [
                    'success' => true,
                    'message' => 'Registration submitted. Please wait for admin approval.',
                    'user'    => new UserResource($user->load('role')),
                ];
            });

            return $result;

        } catch (\Throwable $e) {
            Log::channel('registration')->error('Registration failed.', [
                'user_uuid' => $user->uuid,
                'error'     => $e->getMessage(),
                'line'      => $e->getLine(),
                'file'      => $e->getFile(),
            ]);

            return [
                'success' => false,
                'message' => 'Registration failed. Please try again.',
                'debug'   => $e->getMessage(), 
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ];
        }
    }

    private function storeFile(UploadedFile $file, string $path): string
    {
        return $file->store($path, 'public');
    }
}