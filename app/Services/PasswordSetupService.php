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
        $account = $this->repo->findAccountAwaitingSetupByUuid($uuid);

        if (! $account) {
            Log::channel('auth')->warning('Password setup blocked — account not found or not awaiting setup.', [
                'uuid' => $uuid,
            ]);

            return [
                'success' => false,
                'message' => 'This link is invalid or has already been used.',
            ];
        }

        $isOwner = $account->role->role_name === 'Cafe Owner';
        $result  = [];

        try {
            DB::transaction(function () use ($account, $plainPassword, $isOwner, &$result) {

                $hashedPassword = Hash::make($plainPassword);

                $account = $this->repo->activateOwner($account, $hashedPassword);

                if ($isOwner) {
                    // Owner-only onboarding: activate their branch(es) and
                    // start the free trial subscription.
                    $this->repo->activateBranches($account->user_id);

                    $trialPlan = $this->subscriptionRepo->findTrialPlan();

                    if ($trialPlan) {
                        $this->subscriptionRepo->createTrialSubscription($account->user_id, $trialPlan);

                        Log::channel('auth')->info('Free trial subscription started.', [
                            'owner_uuid' => $account->uuid,
                            'plan'       => $trialPlan->sub_name,
                            'duration'   => $trialPlan->duration_days,
                        ]);
                    } else {
                        Log::channel('auth')->warning('Trial plan not found — subscription not created.', [
                            'owner_uuid' => $account->uuid,
                        ]);
                    }
                }

                Log::channel('auth')->info('Password setup successful — account activated.', [
                    'account_uuid' => $account->uuid,
                    'role'         => $account->role->role_name,
                ]);

                $result = [
                    'success' => true,
                    'message' => $isOwner
                        ? 'Password set successfully. Your account is now active and your 15-day free trial has started!'
                        : 'Password set successfully. Your account is now active.',
                    'user' => new UserResource($account->load('role')),
                ];
            });

            return $result;

        } catch (\Throwable $e) {
            Log::channel('auth')->error('Password setup failed.', [
                'account_uuid' => $account->uuid,
                'error'        => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
            ];
        }
    }
}