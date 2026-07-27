<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $service
    ) {}

    /**
     * GET /api/admin/subscribers
     * Admin — view all users with a subscription: name, email, phone,
     * plan, mode of payment, and amount.
     */
    public function subscribers(Request $request): JsonResponse
    {
        $subscribers = $this->service->listSubscribers($request->input('per_page', 15));

        return response()->json([
            'success'     => true,
            'subscribers' => $subscribers,
        ]);
    }

    /**
     * GET /api/admin/owners/{uuid}/subscription-history
     * Admin — view a specific owner's full subscription history.
     */
    public function ownerHistory(Request $request, string $uuid): JsonResponse
    {
        $history = $this->service->getOwnerSubscriptionHistory($uuid, $request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'history' => $history,
        ]);
    }
}