<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Contracts\PaymentAdapterInterface;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\Payment;
use Carbon\Carbon;

class PayPalSubscriptionController extends Controller
{
    public function __construct(
        private readonly PaymentAdapterInterface $paymentAdapter
    ) {}

    /**
     * Store the initial subscription from the Nuxt frontend checkout
     */
    public function store(Request $request)
    {
        $request->validate([
            'paypal_subscription_id' => 'required|string',
            'plan_uuid' => 'required|exists:subscription_plans,uuid',
        ]);

        $user = $request->user();
        $plan = SubscriptionPlan::where('uuid', $request->plan_uuid)->firstOrFail();

        // Check if the user already has a pending or active subscription for this exact PayPal ID.
        // This is the case when the frontend called actions.subscription.revise() (plan upgrade/downgrade),
        // since PayPal keeps the same subscription ID across a revision.
        $existingSub = Subscription::where('paypal_subscription_id', $request->paypal_subscription_id)->first();
        if ($existingSub) {
            $existingSub->update([
                'sub_plan_id' => $plan->sub_plan_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Subscription plan updated successfully.',
                'subscription' => $existingSub
            ]);
        }

        $subscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'sub_plan_id' => $plan->sub_plan_id,
            'paypal_subscription_id' => $request->paypal_subscription_id,
            'billing_cycle' => $request->input('billing_cycle', 'monthly'),
            'status' => 'pending', // Will become active on first payment webhook
            'start_date' => now(),
            'end_date' => now()->addMonth(), // Temporary until webhook confirms payment
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subscription created successfully. Awaiting payment confirmation.',
            'subscription' => $subscription
        ], 201);
    }

    /**
     * Preview the prorated cost of switching the owner's active subscription to a different plan.
     * Upgrades (new price > current price) require an immediate prorated charge; downgrades take
     * effect at the next billing cycle with no charge.
     */
    public function previewUpgrade(Request $request)
    {
        $request->validate([
            'plan_uuid' => 'required|exists:subscription_plans,uuid',
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ]);

        $user = $request->user();
        $billingCycle = $request->input('billing_cycle', 'monthly');
        $newPlan = SubscriptionPlan::where('uuid', $request->plan_uuid)->firstOrFail();

        $subscription = Subscription::where('user_id', $user->user_id)
            ->where('status', 'active')
            ->latest('start_date')
            ->first();

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'No active subscription to upgrade.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            ...$this->calculateProration($subscription, $newPlan, $billingCycle),
        ]);
    }

    /**
     * Create a one-time PayPal order for the prorated difference owed on an upgrade.
     * The buyer approves this single charge; the recurring plan itself is switched
     * server-to-server in captureUpgradeOrder() once the charge succeeds.
     */
    public function createUpgradeOrder(Request $request)
    {
        $request->validate([
            'plan_uuid' => 'required|exists:subscription_plans,uuid',
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ]);

        $user = $request->user();
        $billingCycle = $request->input('billing_cycle', 'monthly');
        $newPlan = SubscriptionPlan::where('uuid', $request->plan_uuid)->firstOrFail();

        $subscription = Subscription::where('user_id', $user->user_id)
            ->where('status', 'active')
            ->latest('start_date')
            ->first();

        if (!$subscription || !$subscription->paypal_subscription_id) {
            return response()->json([
                'success' => false,
                'message' => 'No active PayPal subscription to upgrade.',
            ], 404);
        }

        $proration = $this->calculateProration($subscription, $newPlan, $billingCycle);

        if (!$proration['is_upgrade'] || $proration['prorated_amount'] <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'This plan change does not require an immediate charge.',
            ], 422);
        }

        $amountCentavos = (int) round($proration['prorated_amount'] * 100);

        try {
            $order = $this->paymentAdapter->createCheckoutSession([
                'amount' => $amountCentavos,
                'description' => "Prorated upgrade to {$newPlan->sub_name}",
                'success_url' => config('services.paypal.success_url'),
                'cancel_url' => url('/api/payment/paypal/cancel'),
                'metadata' => [
                    'subscription_uuid' => $subscription->uuid,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('PayPal upgrade order creation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to start the upgrade payment. Please try again.',
            ], 500);
        }

        Payment::create([
            'user_id' => $user->user_id,
            'payable_type' => Subscription::class,
            'payable_id' => $subscription->sub_id,
            'amount' => $amountCentavos,
            'payment_method_type' => 'paypal_order',
            'payment_instrument' => 'paypal_upgrade',
            'gateway_transaction_id' => $order['id'],
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'order_id' => $order['id'],
            'prorated_amount' => $proration['prorated_amount'],
        ]);
    }

    /**
     * Capture the prorated order and, once payment is confirmed, switch the
     * recurring PayPal subscription over to the new plan.
     */
    public function captureUpgradeOrder(Request $request)
    {
        $request->validate([
            'order_id' => 'required|string',
            'plan_uuid' => 'required|exists:subscription_plans,uuid',
        ]);

        $user = $request->user();
        $newPlan = SubscriptionPlan::where('uuid', $request->plan_uuid)->firstOrFail();

        $payment = Payment::where('gateway_transaction_id', $request->order_id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Upgrade payment not found.',
            ], 404);
        }

        if ($payment->status === 'succeeded') {
            // Already processed — e.g. a retried request. Return current state instead of double-charging logic.
            return response()->json([
                'success' => true,
                'message' => 'Plan already upgraded.',
                'subscription' => Subscription::find($payment->payable_id),
            ]);
        }

        try {
            $capture = $this->paymentAdapter->captureOrder($request->order_id);
        } catch (\Throwable $e) {
            Log::error('PayPal upgrade order capture failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to capture the upgrade payment.',
            ], 500);
        }

        $captureStatus = $capture['purchase_units'][0]['payments']['captures'][0]['status'] ?? null;

        if ($captureStatus !== 'COMPLETED') {
            $payment->update(['status' => 'failed']);

            return response()->json([
                'success' => false,
                'message' => 'PayPal did not complete the payment.',
            ], 422);
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::find($payment->payable_id);

        if (!$subscription || !$subscription->paypal_subscription_id) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription no longer exists.',
            ], 404);
        }

        $newPaypalPlanId = $subscription->billing_cycle === 'yearly'
            ? ($newPlan->paypal_yearly_plan_id ?? $newPlan->paypal_plan_id)
            : $newPlan->paypal_plan_id;

        if ($newPaypalPlanId) {
            try {
                $this->paymentAdapter->reviseSubscription($subscription->paypal_subscription_id, $newPaypalPlanId);
            } catch (\Throwable $e) {
                // The charge succeeded but PayPal rejected the plan switch (e.g. it decided the
                // buyer needs to re-approve). Keep the payment as captured and flag this loudly —
                // silently leaving them charged with no plan change would be worse than a retry prompt.
                $payment->update(['status' => 'succeeded']);

                Log::error('PayPal subscription revise failed after upgrade payment captured', [
                    'subscription_id' => $subscription->paypal_subscription_id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment succeeded, but updating your recurring plan failed. Please contact support.',
                ], 500);
            }
        }

        $payment->update(['status' => 'succeeded']);
        $subscription->update(['sub_plan_id' => $newPlan->sub_plan_id]);

        Log::info("Subscription {$subscription->paypal_subscription_id} upgraded immediately to {$newPlan->sub_name}");

        return response()->json([
            'success' => true,
            'message' => 'Plan upgraded successfully.',
            'subscription' => $subscription->fresh('plan'),
        ]);
    }

    /**
     * Prorated cost of switching from the subscription's current plan to $newPlan.
     * Downgrades (or lateral moves) are flagged as not-an-upgrade with a $0 charge —
     * those take effect at the next billing cycle instead, at no extra cost.
     */
    private function calculateProration(Subscription $subscription, SubscriptionPlan $newPlan, string $billingCycle): array
    {
        $oldPlan = SubscriptionPlan::find($subscription->sub_plan_id);

        $oldPrice = (float) ($billingCycle === 'yearly' ? ($oldPlan->yearly_price ?? $oldPlan->price) : $oldPlan->price);
        $newPrice = (float) ($billingCycle === 'yearly' ? ($newPlan->yearly_price ?? $newPlan->price) : $newPlan->price);

        $priceDifference = round($newPrice - $oldPrice, 2);
        $isUpgrade = $priceDifference > 0;

        $totalDays = $billingCycle === 'yearly' ? 365 : 30;
        $now = Carbon::now();
        $endDate = $subscription->end_date ? Carbon::parse($subscription->end_date) : $now->copy()->addDays($totalDays);
        $remainingDays = $endDate->greaterThan($now) ? min($totalDays, (int) $now->diffInDays($endDate)) : 0;

        $proratedAmount = $isUpgrade
            ? round($priceDifference * ($remainingDays / $totalDays), 2)
            : 0.0;

        return [
            'is_upgrade' => $isUpgrade,
            'current_plan_name' => $oldPlan->sub_name ?? null,
            'new_plan_name' => $newPlan->sub_name,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'remaining_days' => $remainingDays,
            'total_days' => $totalDays,
            'prorated_amount' => $proratedAmount,
            'next_billing_date' => $endDate->toISOString(),
        ];
    }

    /**
     * Webhook listener for PayPal automated billing
     */
    public function webhook(Request $request)
    {
        $payload = $request->all();

        // 1. Verify Webhook Signature
        if (!$this->verifyWebhook($request)) {
            Log::warning('PayPal Webhook Verification Failed', ['payload' => $payload]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $eventType = $payload['event_type'] ?? null;

        Log::info('PayPal Webhook Received', ['event' => $eventType]);

        // 2. Handle PAYMENT.SALE.COMPLETED (Recurring or initial payment)
        if ($eventType === 'PAYMENT.SALE.COMPLETED') {
            $resource = $payload['resource'];
            $subscriptionId = $resource['billing_agreement_id'] ?? null; // This is the I-XXXX ID
            $saleId = $resource['id'] ?? null; // Unique per-charge sale ID
            $amount = $resource['amount']['total'] ?? 0;
            $currency = $resource['amount']['currency'] ?? 'PHP';

            if ($subscriptionId) {
                $subscription = Subscription::where('paypal_subscription_id', $subscriptionId)->first();

                if ($subscription && !Payment::where('gateway_transaction_id', $saleId)->exists()) {
                    // Record the payment
                    Payment::create([
                        'user_id' => $subscription->user_id,
                        'payable_type' => Subscription::class,
                        'payable_id' => $subscription->sub_id,
                        'amount' => $amount,
                        'payment_method_type' => 'paypal',
                        'payment_instrument' => 'paypal_subscription',
                        'gateway_transaction_id' => $saleId ?? $subscriptionId, // Unique sale ID per charge (falls back to subscription ID if PayPal omits it)
                        'status' => 'completed',
                    ]);

                    // Extend end date by 1 month or 1 year
                    $plan = SubscriptionPlan::find($subscription->sub_plan_id);
                    $newEndDate = now()->addMonth();

                    if ($plan && $amount >= $plan->yearly_price && $plan->yearly_price > 0) {
                        $newEndDate = now()->addYear();
                    }

                    // Cancel any other active subscriptions for this user (e.g., Trials)
                    Subscription::where('user_id', $subscription->user_id)
                        ->where('sub_id', '!=', $subscription->sub_id)
                        ->where('status', 'active')
                        ->update(['status' => 'cancelled']);

                    // Activate and extend subscription
                    $subscription->update([
                        'status' => 'active',
                        'end_date' => $newEndDate
                    ]);

                    Log::info("Subscription $subscriptionId activated/extended to $newEndDate");
                }
            }
        }

        // 2.5 Handle Subscription Upgrades (Revise)
        if ($eventType === 'BILLING.SUBSCRIPTION.UPDATED') {
            $subscriptionId = $payload['resource']['id'] ?? null;
            $planId = $payload['resource']['plan_id'] ?? null;

            if ($subscriptionId && $planId) {
                $subscription = Subscription::where('paypal_subscription_id', $subscriptionId)->first();
                if ($subscription) {
                    $newPlan = SubscriptionPlan::where('paypal_plan_id', $planId)
                                               ->orWhere('paypal_yearly_plan_id', $planId)
                                               ->first();
                    if ($newPlan) {
                        $subscription->update([
                            'sub_plan_id' => $newPlan->sub_plan_id
                        ]);
                        Log::info("Subscription $subscriptionId plan upgraded to {$newPlan->sub_name} ($planId)");
                    }
                }
            }
        }

        // 3. Handle Cancellations
        if ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED' || $eventType === 'BILLING.SUBSCRIPTION.SUSPENDED') {
            $subscriptionId = $payload['resource']['id'] ?? null;

            if ($subscriptionId) {
                Subscription::where('paypal_subscription_id', $subscriptionId)->update([
                    'status' => 'cancelled',
                    'cancel_at_period_end' => true
                ]);
                Log::info("Subscription $subscriptionId cancelled via Webhook.");
            }
        }

        // Always return 200 OK so PayPal doesn't retry
        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Verify the PayPal Webhook Signature
     */
    private function verifyWebhook(Request $request)
    {
        $clientId = env('PAYPAL_CLIENT_ID');
        $clientSecret = env('PAYPAL_CLIENT_SECRET');
        $webhookId = env('PAYPAL_WEBHOOK_ID');
        $mode = env('PAYPAL_MODE', 'sandbox');
        
        $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        // Get Access Token
        $http = config('app.env') === 'local' ? Http::withoutVerifying() : Http::asForm();
        $tokenResponse = $http->withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post("$baseUrl/v1/oauth2/token", ['grant_type' => 'client_credentials']);

        if (!$tokenResponse->successful()) {
            return false;
        }

        $accessToken = $tokenResponse->json('access_token');

        // Verify Signature
        $verifyResponse = (config('app.env') === 'local' ? Http::withoutVerifying() : Http::asJson())
            ->withToken($accessToken)
            ->post("$baseUrl/v1/notifications/verify-webhook-signature", [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => json_decode($request->getContent(), true)
            ]);

        if ($verifyResponse->successful() && $verifyResponse->json('verification_status') === 'SUCCESS') {
            return true;
        }

        return false;
    }
}
