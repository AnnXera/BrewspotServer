<?php

namespace App\Services;

use App\Contracts\MailAdapterInterface;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Mail\RegistrationSubmittedMail;
use App\Models\ApprovalList;
use App\Models\User;
use App\Notifications\OwnerApplicationSubmitted;
use App\Repository\RegistrationRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RegistrationService
{
    public function __construct(
        private readonly RegistrationRepository $repo,
        private readonly MailAdapterInterface $mailer,
        private readonly NotificationService $notifications
    ) {}

    /**
     * Registration links carry the user's UUID; a missing user means a dead link.
     */
    public function registerByUuid(string $uuid, array $payload): array
    {
        $user = $this->repo->findUserByUuid($uuid);

        if (! $user) {
            return [
                'success' => false,
                'http'    => 404,
                'message' => 'Invalid or expired registration link. No account found for this UUID.',
            ];
        }

        return $this->register($user, $payload);
    }

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

        $rejection = $this->repo->findLatestRejection($user);

        if ($rejection && ! $rejection->can_resubmit) {
            Log::channel('registration')->warning('Registration blocked — rejection is final.', [
                'user_uuid' => $user->uuid,
            ]);

            return [
                'success' => false,
                'message' => 'This application was closed by an administrator and cannot be resubmitted.',
            ];
        }

        return $this->submit($user, $payload);
    }

    /**
     * Edit-and-resubmit straight from the application page, without verifying the
     * email again: only while the owner is rejected and the admin allowed it.
     */
    public function resubmitByUuid(string $uuid, array $payload): array
    {
        $user = $this->repo->findUserByUuid($uuid);

        if (! $user || $user->status !== 'rejected' || ! $this->repo->findResubmittableApplication($user)) {
            Log::channel('registration')->warning('Resubmission blocked — not a resubmittable rejection.', [
                'user_uuid' => $uuid,
                'status'    => $user?->status,
            ]);

            return [
                'success' => false,
                'http'    => 403,
                'message' => 'This application cannot be edited.',
            ];
        }

        return $this->submit($user, $payload);
    }

    /**
     * Stores the application: profile, documents, cafe, main branch and approval
     * entry. On a resubmission the rejected application is archived first.
     */
    private function submit(User $user, array $payload): array
    {
        // Set when this corrects a rejected application, so admins are told it is a resubmission
        $previousRejection = $this->repo->findLatestRejection($user);

        $result = [];
        $createdCafe = null;
        $createdBranch = null;
        $createdApproval = null;

        try {
            DB::transaction(function () use ($user, $payload, &$result, &$createdCafe, &$createdBranch, &$createdApproval) {

                $userFolder = 'users/' . $user->uuid;

                // 0. Resubmission: note the rejected application's files (fields sent
                //    as "keep" reuse them), then archive it before the new rows go in,
                //    which also frees its TIN, cafe email and phone for the new branch.
                $kept = $this->keptFilePaths($user);
                if ($kept !== null) {
                    $this->repo->archiveRejectedApplication($user);
                }

                // 1. Update user profile
                $user = $this->repo->updateUserProfile($user, $payload);

                // 2. Store user government ID (Front & optional Back) — PRIVATE
                $idFilePath = $this->placeFile(
                    $payload['file'],
                    $kept['file'] ?? null,
                    "{$userFolder}/user_documents"
                );
                $idBackFilePath = ! empty($payload['file_back'])
                    ? $this->placeFile($payload['file_back'], $kept['file_back'] ?? null, "{$userFolder}/user_documents")
                    : null;

                $this->repo->createUserDocument(
                    $user->user_id,
                    $idFilePath,
                    $payload['id_type'],
                    $idBackFilePath
                );

                // 3. Create cafe so we have the UUID for the folder path
                $cafe        = $this->repo->createCafe($user->user_id, $payload['cafe_name']);
                $createdCafe = $cafe;
                $cafeFolder  = "{$userFolder}/cafes/{$cafe->uuid}";

                // 4. Store cafe DTI/SEC document — PRIVATE
                $dtiSecFilePath = $this->placeFile(
                    $payload['dti_sec_file'],
                    $kept['dti_sec_file'] ?? null,
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
                    $cafePicturePath = $this->placeFile(
                        $payload['cafe_picture'],
                        $kept['cafe_picture'] ?? null,
                        "{$branchFolder}/cafe_pictures",
                        'public'
                    );
                    $branch->update(['cafe_picture' => $cafePicturePath]);
                }

                // 7. Store branch documents — PRIVATE
                $birFilePath = $this->placeFile($payload['bir_file'], $kept['bir_file'] ?? null, "{$branchFolder}/branch_documents");

                $this->repo->createBranchDocument($branch->branch_id, 'BIR', $birFilePath, [
                    'registered_at' => $payload['bir_registered_at'],
                    'tin_number'    => $payload['tin_number'],
                    'vat'           => $payload['vat'],
                ]);

                // 8. Create approval entry for admin review
                $createdApproval = $this->repo->createApprovalEntry(
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

            // Let admins know there's an application waiting in Approvals
            if (!empty($result['success']) && $createdCafe && $createdApproval) {
                $this->notifications->notifyAdmins(
                    new OwnerApplicationSubmitted($user->refresh(), $createdCafe, $createdApproval, $previousRejection)
                );
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
        $user = $this->repo->findUserByUuid($uuid);

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
                        'has_back' => ! empty($userDoc?->file_back),
                    ],
                    'cafe_document' => [
                        'type'     => $cafeDoc?->doc_type,
                        'uploaded' => ! empty($cafeDoc),
                    ],
                    'bir' => [
                        'type'          => 'BIR',
                        'uploaded'      => $branchDocs->contains('doc_type', 'BIR'),
                        'registered_at' => $branchDocs->firstWhere('doc_type', 'BIR')?->registered_at,
                        'expired_at'    => $branchDocs->firstWhere('doc_type', 'BIR')?->expired_at,
                        'tin_number'    => $branchDocs->firstWhere('doc_type', 'BIR')?->tin_number,
                        'vat'           => $branchDocs->firstWhere('doc_type', 'BIR')?->vat,
                    ],
                ],
                'approval' => [
                    'status'           => $approval?->status ?? $user->status,
                    'reason'           => $approval?->reason,
                    'can_resubmit'     => $approval?->status === 'rejected' && $approval->can_resubmit,
                    'submitted_at'     => $approval?->created_at?->toISOString() ?? $user->created_at?->toISOString(),
                    'reviewed_at'      => $approval?->reviewed_at?->toISOString(),
                ],
                // Present only while the page may edit and resubmit this application
                'editable' => $user->status === 'rejected' && ($resubmittable = $this->repo->findResubmittableApplication($user))
                    ? $this->editableApplication($user, $resubmittable)
                    : null,
            ],
        ];
    }

    /**
     * Prefill data for editing a rejected application: the previous answers,
     * which documents can be kept, and the admin's reason. Only for an owner who
     * has re-verified their email (filling_application), like registration itself.
     */
    public function getPreviousApplication(string $uuid): array
    {
        $user = $this->repo->findUserByUuid($uuid);

        if (! $user || $user->status !== 'filling_application') {
            return ['success' => false, 'http' => 404, 'message' => 'No application to resubmit.'];
        }

        $approval = $this->repo->findResubmittableApplication($user);

        return [
            'success'     => true,
            'application' => $approval ? $this->editableApplication($user, $approval) : null,
        ];
    }

    /**
     * A rejected application's answers in registration-field form, plus the
     * file names of documents that can be sent back as "keep".
     */
    private function editableApplication(User $user, ApprovalList $approval): array
    {
        $cafe    = $approval->cafe;
        $branch  = $approval->branch;
        $userDoc = $user->documents()->latest('user_doc_id')->first();
        $cafeDoc = $cafe->documents->sortByDesc('cafe_doc_id')->first();
        $bir     = $branch->documents->firstWhere('doc_type', 'BIR');

        return [
            'reason'            => $approval->reason,
            'rejected_at'       => $approval->reviewed_at?->toISOString(),
            'firstname'         => $user->firstname,
            'middlename'        => $user->middlename,
            'lastname'          => $user->lastname,
            'username'          => $user->username,
            'phone_number'      => $user->phone_number,
            'owner_address'     => $user->address,
            'id_type'           => $userDoc?->id_type,
            'cafe_name'         => $cafe->cafe_name,
            'cafe_doc_type'     => $cafeDoc?->doc_type,
            'branch_name'       => $branch->branch_name,
            'address'           => $branch->address,
            'cafe_email'        => $branch->cafe_email,
            'cafe_phonenumber'  => $branch->cafe_phonenumber,
            'bir_registered_at' => $bir?->registered_at ? substr((string) $bir->registered_at, 0, 10) : null,
            'tin_number'        => $bir?->tin_number,
            'vat'               => $bir?->vat,
            // File names of documents that can be sent back as "keep" instead of a new upload
            'documents' => [
                'file'         => $userDoc?->file ? basename($userDoc->file) : null,
                'file_back'    => $userDoc?->file_back ? basename($userDoc->file_back) : null,
                'dti_sec_file' => $cafeDoc?->file ? basename($cafeDoc->file) : null,
                'bir_file'     => $bir?->file ? basename($bir->file) : null,
                'cafe_picture' => $branch->cafe_picture ? basename($branch->cafe_picture) : null,
            ],
        ];
    }

    /**
     * Stored paths of the resubmittable rejected application's files, keyed by
     * registration field, or null when this is not a resubmission.
     */
    private function keptFilePaths(User $user): ?array
    {
        $approval = $this->repo->findResubmittableApplication($user);

        if (! $approval) {
            return null;
        }

        $userDoc = $user->documents()->latest('user_doc_id')->first();

        return [
            'file'         => $userDoc?->file,
            'file_back'    => $userDoc?->file_back,
            'dti_sec_file' => $approval->cafe->documents->sortByDesc('cafe_doc_id')->first()?->file,
            'bir_file'     => $approval->branch->documents->firstWhere('doc_type', 'BIR')?->file,
            'cafe_picture' => $approval->branch->cafe_picture,
        ];
    }

    /**
     * A new temp upload is moved into place. "keep" copies the previous
     * application's file instead, so the archived attempt keeps its own copy.
     */
    private function placeFile(string $value, ?string $keptPath, string $path, string $disk = 'local'): string
    {
        if ($value !== RegisterRequest::KEEP_FILE) {
            return $this->storeFile($value, $path, $disk);
        }

        if (! $keptPath || ! Storage::disk($disk)->exists($keptPath)) {
            throw new \RuntimeException('Previous document to keep was not found.');
        }

        $finalPath = rtrim($path, '/') . '/' . basename($keptPath);

        if ($finalPath !== $keptPath) {
            Storage::disk($disk)->copy($keptPath, $finalPath);
        }

        return $finalPath;
    }

    /**
     * Move a temp-uploaded file to its permanent location and return the new path.
     *
     * @param  string $tempPath  Relative path on the local disk (e.g. "temp/abc.pdf")
     * @param  string $path      Target directory (relative, no trailing slash)
     * @param  string $disk      'local' (private, default) or 'public'
     * @throws \RuntimeException if the temp file cannot be found — surfaces inside the DB transaction so the registration rolls back cleanly.
     */
    private function storeFile(string $tempPath, string $path, string $disk = 'local'): string
    {
        if (! \Illuminate\Support\Facades\Storage::disk('local')->exists($tempPath)) {
            throw new \RuntimeException("Temp file not found on disk: {$tempPath}");
        }

        $filename  = basename($tempPath);
        $finalPath = rtrim($path, '/') . '/' . $filename;

        if ($disk === 'local') {
            \Illuminate\Support\Facades\Storage::disk('local')->move($tempPath, $finalPath);
        } else {
            $content = \Illuminate\Support\Facades\Storage::disk('local')->get($tempPath);
            \Illuminate\Support\Facades\Storage::disk($disk)->put($finalPath, $content);
            \Illuminate\Support\Facades\Storage::disk('local')->delete($tempPath);
        }

        return $finalPath;
    }
}