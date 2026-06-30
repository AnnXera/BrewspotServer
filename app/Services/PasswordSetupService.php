<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Repository\PasswordSetupRepository;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PasswordSetupService
{
    public function __construct(
        private readonly PasswordSetupRepository $repo,
        private readonly SubscriptionRepository $subscriptionRepo
    ) {}

    public function setupPassword(string $uuid, string $plainPassword): array
    {
        $owner = $this->repo->findApprovedOwnerByUuid($uuid);

        if (! $owner) {
            Log::channel('auth')->warning('Password setup blocked — owner not found or not approved.', [
                'uuid' => $uuid,
            ]);

            return [
                'success' => false,
                'message' => 'This link is invalid or has already been used.',
            ];
        }

        $result = [];

        try {
            DB::transaction(function () use ($owner, $plainPassword, &$result) {

                $hashedPassword = Hash::make($plainPassword);

                // 1. Activate owner account
                $owner = $this->repo->activateOwner($owner, $hashedPassword);

                // 2. Activate their branch(es)
                $this->repo->activateBranches($owner->user_id);

                // 3. Start free trial subscription
                $trialPlan = $this->subscriptionRepo->findTrialPlan();

                if ($trialPlan) {
                    $this->subscriptionRepo->createTrialSubscription($owner->user_id, $trialPlan);

                    Log::channel('auth')->info('Free trial subscription started.', [
                        'owner_uuid' => $owner->uuid,
                        'plan'       => $trialPlan->sub_name,
                        'duration'   => $trialPlan->duration_days,
                    ]);
                } else {
                    Log::channel('auth')->warning('Trial plan not found — subscription not created.', [
                        'owner_uuid' => $owner->uuid,
                    ]);
                }

                Log::channel('auth')->info('Password setup successful — account activated.', [
                    'owner_uuid' => $owner->uuid,
                ]);

                $result = [
                    'success' => true,
                    'message' => 'Password set successfully. Your account is now active and your 15-day free trial has started!',
                    'user'    => new UserResource($owner->load('role')),
                ];
            });

            return $result;

        } catch (\Throwable $e) {
            Log::channel('auth')->error('Password setup failed.', [
                'owner_uuid' => $owner->uuid,
                'error'      => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
            ];
        }
    }
}