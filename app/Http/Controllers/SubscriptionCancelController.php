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
     * GET /api/payment/paypal/cancel
     * Called directly by PayPal when the user clicks "Cancel and return to Merchant"
     */
    public function handleGetCancel(Request $request)
    {
        $this->processCancellation($request);

        // Redirect the user to the frontend cancel page
        $frontendCancelUrl = config('services.paypal.cancel_url');
        return redirect($frontendCancelUrl);
    }

    private function processCancellation(Request $request): void
    {
        Log::channel('owner')->info('Cancel API hit', $request->all());
        $token = $request->input('token'); // Used for one-time checkout
        $baToken = $request->input('ba_token'); // Setup token for native sub
        $subscriptionId = $request->input('subscription_id'); // Subscription ID for native sub

        // Handle one-time checkout cancellation
        if ($token) {
            $payment = $this->paymentRepo->findByGatewayTransactionId($token);
            if ($payment && $payment->status === 'pending') {
                $this->paymentRepo->markFailed($payment, 'paypal');

                $subscription = $payment->payable;
                if ($subscription instanceof Subscription && $subscription->status === 'pending') {
                    $this->subscriptionRepo->markFailed($subscription);
                }
                
                Log::channel('owner')->info('Cancelled pending checkout.', [
                    'order_id' => $token
                ]);
            }
        }

        // Handle native subscription cancellation
        $nativeToken = $subscriptionId ?? $baToken;
        if ($nativeToken) {
            $subscription = $this->subscriptionRepo->findByPayPalSubscriptionId($nativeToken);
            if ($subscription && $subscription->status === 'pending') {
                $this->subscriptionRepo->markFailed($subscription);
                
                Log::channel('owner')->info('Cancelled pending native subscription.', [
                    'paypal_subscription_id' => $nativeToken
                ]);
            }
        }
    }
}
