<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionCancelController extends Controller
{
    public function __construct(
        private readonly SubscriptionCheckoutService $service
    ) {}

    /**
     * POST /api/subscriptions/cancel
     */
    public function cancel(Request $request): JsonResponse
    {
        $this->service->cancelPendingCheckout($this->sessionId($request));

        return response()->json([
            'success' => true,
            'message' => 'Transaction cancelled successfully.',
        ]);
    }

    /**
     * GET /api/payment/cancel
     * PayMongo's cancel_url — hit when the owner backs out of the hosted checkout page.
     * The pending records are closed off here before handing the browser to the frontend.
     */
    public function handleGetCancel(Request $request): RedirectResponse
    {
        $this->service->cancelPendingCheckout($this->sessionId($request));

        return redirect(config('services.paymongo.cancel_url'));
    }

    /**
     * PayMongo echoes the checkout session id back; older callers may still send
     * `token`, and the frontend cancel button posts the id explicitly.
     */
    private function sessionId(Request $request): ?string
    {
        Log::channel('owner')->info('Cancel API hit', $request->all());

        return $request->input('checkout_session_id')
            ?? $request->input('id')
            ?? $request->input('token');
    }
}
