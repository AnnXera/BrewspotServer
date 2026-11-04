<?php

namespace App\Services;

use App\Http\Resources\AdminSubscriberResource;
use App\Http\Resources\PaymentResource;
use App\Repository\PaymentRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserProfileRepository;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly UserProfileRepository $users
    ) {}

    /**
     * Admin — list all subscribers with user info, plan, and latest payment.
     */
    public function listSubscribers(int $perPage = 15)
    {
        Log::channel('admin')->info('Admin listed subscribers.', ['per_page' => $perPage]);

        $subscriptions = $this->subscriptionRepository->listSubscribers($perPage);

        return $subscriptions->through(fn ($subscription) => new AdminSubscriberResource($subscription));
    }

    /**
     * Admin — view a specific owner's full subscription history.
     */
    public function getOwnerSubscriptionHistory(string $ownerUuid, int $perPage = 15)
    {
        Log::channel('admin')->info('Admin viewed owner payment history.', [
            'owner_uuid' => $ownerUuid,
            'per_page'   => $perPage,
        ]);

        $owner = $this->users->findByUuidOrFail($ownerUuid);
        $history = $this->paymentRepository->getSubscriptionPaymentsByUserId($owner->user_id, $perPage);

        return $history->through(fn ($payment) => new PaymentResource($payment));
    }
}