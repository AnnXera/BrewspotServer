<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\Payment;
use Carbon\Carbon;

class PayPalSubscriptionController extends Controller
{
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

        // Check if the user already has a pending or active subscription for this exact PayPal ID
        $existingSub = Subscription::where('paypal_subscription_id', $request->paypal_subscription_id)->first();
        if ($existingSub) {
            return response()->json([
                'message' => 'Subscription exists. If this is an upgrade, it will be processed shortly.',
                'subscription' => $existingSub
            ]);
        }
        
        $subscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'sub_plan_id' => $plan->sub_plan_id,
            'paypal_subscription_id' => $request->paypal_subscription_id,
            'status' => 'pending', // Will become active on first payment webhook
            'start_date' => now(),
            'end_date' => now()->addMonth(), // Temporary until webhook confirms payment
        ]);

        return response()->json([
            'message' => 'Subscription created successfully. Awaiting payment confirmation.',
            'subscription' => $subscription
        ], 201);
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
            $amount = $resource['amount']['total'] ?? 0;
            $currency = $resource['amount']['currency'] ?? 'PHP';

            if ($subscriptionId) {
                $subscription = Subscription::where('paypal_subscription_id', $subscriptionId)->first();

                if ($subscription) {
                    // Record the payment
                    Payment::create([
                        'user_id' => $subscription->user_id,
                        'payable_type' => Subscription::class,
                        'payable_id' => $subscription->sub_id,
                        'amount' => $amount,
                        'payment_method_type' => 'paypal',
                        'payment_instrument' => 'paypal_subscription',
                        'gateway_transaction_id' => $subscriptionId, // Logging the subscription ID as the transaction ID for reference
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
