<?php

namespace App\Http\Controllers;

use App\Repository\PaymentRepository;
use App\Repository\SubscriptionRepository;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SubscriptionCancelController extends Controller
{
    public function __construct(
        private readonly PaymentRepository $paymentRepo,
        private readonly SubscriptionRepository $subscriptionRepo
    ) {}

    /**
     * POST /api/subscriptions/cancel
     */
    public function cancel(Request $request): JsonResponse
    {
        $this->processCancellation($request);

        return response()->json([
            'success' => true, 
            'message' => 'Transaction cancelled successfully.'
        ], 200);
    }

    /**
     * GET /api/payment/cancel
     * PayMongo's cancel_url — hit when the owner backs out of the hosted checkout page.
     * The pending records are closed off here before handing the browser to the frontend.
     */
    public function handleGetCancel(Request $request)
    {
        $this->processCancellation($request);

        return redirect(config('services.paymongo.cancel_url'));
    }

    private function processCancellation(Request $request): void
    {
        Log::channel('owner')->info('Cancel API hit', $request->all());

        // PayMongo echoes the checkout session id back; older callers may still send
        // `token`, and the frontend cancel button posts the id explicitly.
        $sessionId = $request->input('checkout_session_id')
            ?? $request->input('id')
            ?? $request->input('token');

        if (! $sessionId) {
            return;
        }

        $payment = $this->paymentRepo->findByGatewayTransactionId($sessionId);

        if (! $payment || $payment->status !== 'pending') {
            return;
        }

        $this->paymentRepo->markFailed($payment, 'paymongo');

        $subscription = $payment->payable;

        if ($subscription instanceof Subscription && $subscription->status === 'pending') {
            $this->subscriptionRepo->markFailed($subscription);
        }

        Log::channel('owner')->info('Cancelled pending checkout.', [
            'checkout_session_id' => $sessionId,
        ]);
    }
}
