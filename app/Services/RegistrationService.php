<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;
use App\Http\Resources\UserResource;
use App\Mail\RegistrationSubmittedMail;
use App\Models\User;
use App\Repository\RegistrationRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegistrationService
{
    public function __construct(
        private readonly RegistrationRepository $repo,
        private readonly MailAdapterInterface $mailer
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
        $createdCafe = null;
        $createdBranch = null;

        try {
            DB::transaction(function () use ($user, $payload, &$result, &$createdCafe, &$createdBranch) {

                $userFolder = 'users/' . $user->uuid;

                // 1. Update user profile
                $user = $this->repo->updateUserProfile($user, $payload);

                // 2. Store user government ID — PRIVATE
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
                $cafe        = $this->repo->createCafe($user->user_id, $payload['cafe_name']);
                $createdCafe = $cafe;
                $cafeFolder  = "{$userFolder}/cafes/{$cafe->uuid}";

                // 4. Store cafe DTI/SEC document — PRIVATE
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
                $branch        = $this->repo->createBranch($cafe->cafe_id, $payload);
                $createdBranch = $branch;
                $branchFolder  = "{$cafeFolder}/branches/{$branch->uuid}";

                // 6. Store cafe picture if provided — PUBLIC (meant to be displayed)
                if (isset($payload['cafe_picture'])) {
                    $cafePicturePath = $this->storeFile(
                        $payload['cafe_picture'],
                        "{$branchFolder}/cafe_pictures",
                        'public'
                    );
                    $branch->update(['cafe_picture' => $cafePicturePath]);
                }

                // 7. Store branch documents — PRIVATE
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

            // Send registration confirmation email with application view link
            if (!empty($result['success']) && $createdCafe && $createdBranch) {
                try {
                    $this->mailer->sendMailable(
                        $user->email,
                        new RegistrationSubmittedMail($user, $createdCafe, $createdBranch)
                    );
                } catch (\Throwable $mailError) {
                    Log::channel('registration')->warning('Failed to send registration confirmation email.', [
                        'user_uuid' => $user->uuid,
                        'email'     => $user->email,
                        'error'     => $mailError->getMessage(),
                    ]);
                }
            }

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

    /**
     * Get application details for public/guest review page by user UUID.
     */
    public function getApplicationDetails(string $uuid): array
    {
        $user = User::where('uuid', $uuid)->first();

        if (! $user) {
            return [
                'success' => false,
                'message' => 'Application not found for this account.',
            ];
        }

        $cafe = $this->repo->findLatestCafeForUser($user->user_id);
        $branch = $cafe ? $cafe->branches()->latest('branch_id')->first() : null;
        $userDoc = $user->documents()->latest('user_doc_id')->first();
        $cafeDoc = $cafe ? $cafe->documents()->latest('cafe_doc_id')->first() : null;
        $branchDocs = $branch ? $branch->documents()->get() : collect();
        $approval = $user->approvals()->latest('approval_id')->first();

        return [
            'success' => true,
            'application' => [
                'user' => [
                    'uuid'         => $user->uuid,
                    'firstname'    => $user->firstname,
                    'middlename'   => $user->middlename,
                    'lastname'     => $user->lastname,
                    'username'     => $user->username,
                    'email'        => $user->email,
                    'phone_number' => $user->phone_number,
                    'address'      => $user->address,
                    'status'       => $user->status,
                    'created_at'   => $user->created_at?->toISOString(),
                ],
                'cafe' => $cafe ? [
                    'uuid'      => $cafe->uuid,
                    'cafe_name' => $cafe->cafe_name,
                    'doc_type'  => $cafeDoc?->doc_type,
                ] : null,
                'branch' => $branch ? [
                    'uuid'             => $branch->uuid,
                    'branch_name'      => $branch->branch_name,
                    'address'          => $branch->address,
                    'cafe_email'       => $branch->cafe_email,
                    'cafe_phonenumber' => $branch->cafe_phonenumber,
                    'branch_type'      => $branch->branch_type,
                    'status'           => $branch->status,
                ] : null,
                'documents' => [
                    'government_id' => [
                        'type'     => $userDoc?->id_type,
                        'uploaded' => ! empty($userDoc),
                    ],
                    'cafe_document' => [
                        'type'     => $cafeDoc?->doc_type,
                        'uploaded' => ! empty($cafeDoc),
                    ],
                    'bir' => [
                        'type'     => 'BIR',
                        'uploaded' => $branchDocs->contains('doc_type', 'BIR'),
                    ],
                    'mayors_permit' => [
                        'type'     => 'mayors_permit',
                        'uploaded' => $branchDocs->contains('doc_type', 'mayors_permit'),
                    ],
                    'sanitary_permit' => [
                        'type'     => 'sanitary_permit',
                        'uploaded' => $branchDocs->contains('doc_type', 'sanitary_permit'),
                    ],
                ],
                'approval' => [
                    'status'           => $approval?->status ?? $user->status,
                    'reason'           => $approval?->reason,
                    'submitted_at'     => $approval?->created_at?->toISOString() ?? $user->created_at?->toISOString(),
                    'reviewed_at'      => $approval?->reviewed_at?->toISOString(),
                ],
            ],
        ];
    }

    /**
     * @param string $disk 'local' (private, default) or 'public'
     */
    private function storeFile(UploadedFile $file, string $path, string $disk = 'local'): string
    {
        return $file->store($path, $disk);
    }
}