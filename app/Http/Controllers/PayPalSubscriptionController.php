<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\Payment;
use App\Mail\SubscriptionPaymentMail;
use App\Mail\SubscriptionPlanChangedMail;
use App\Mail\SubscriptionCancelledMail;
use Illuminate\Support\Facades\Mail;

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

        // Check if the user already has a pending or active subscription for this exact PayPal ID.
        // This is the case when the frontend called actions.subscription.revise() (plan upgrade/downgrade),
        // since PayPal keeps the same subscription ID across a revision.
        $existingSub = Subscription::where('paypal_subscription_id', $request->paypal_subscription_id)->first();
        if ($existingSub) {
            // PayPal does not prorate: revise() only changes what PayPal will bill on the NEXT
            // cycle, it doesn't charge anything now. So don't switch sub_plan_id yet either —
            // that would unlock the new plan's features for the rest of the current cycle for
            // free. Stash the change as "pending" and only apply it once the next
            // PAYMENT.SALE.COMPLETED webhook confirms the new plan was actually paid for.
            $existingSub->update([
                'pending_sub_plan_id' => $plan->sub_plan_id,
                'pending_billing_cycle' => $request->input('billing_cycle', $existingSub->billing_cycle),
            ]);

            if ($user) {
                Mail::to($user->email)->send(new SubscriptionPlanChangedMail(
                    ownerName: $user->firstname ?? $user->username ?? 'there',
                    newPlanName: $plan->sub_name,
                    effectiveDate: $existingSub->end_date ? $existingSub->end_date->format('F j, Y') : 'your next billing cycle'
                ));
            }

            return response()->json([
                'success' => true,
                'message' => 'Plan change scheduled. It will take effect on your next billing date.',
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
                    // A real charge landed — apply any plan change that was waiting on it.
                    if ($subscription->pending_sub_plan_id) {
                        $subscription->update([
                            'sub_plan_id' => $subscription->pending_sub_plan_id,
                            'billing_cycle' => $subscription->pending_billing_cycle ?? $subscription->billing_cycle,
                            'pending_sub_plan_id' => null,
                            'pending_billing_cycle' => null,
                        ]);
                        $subscription->refresh();
                    }

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

                    // Extend end date based on billing cycle + 24 hours grace period for PayPal batching
                    $plan = SubscriptionPlan::find($subscription->sub_plan_id);
                    $newEndDate = match ($subscription->billing_cycle) {
                        'yearly' => now()->addYear()->addDay(),
                        'daily'  => now()->addDay()->addDay(),
                        default  => now()->addMonth()->addDay(),
                    };

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

                    // Send receipt
                    $owner = $subscription->user;
                    if ($owner) {
                        Mail::to($owner->email)->send(new SubscriptionPaymentMail(
                            ownerName: $owner->firstname ?? $owner->username ?? 'there',
                            planName: $plan->sub_name ?? 'Unknown Plan',
                            status: 'active',
                            amount: number_format($amount, 2),
                            endDate: $newEndDate->format('F j, Y'),
                        ));
                    }
                }
            }
        }

        // 2.5 Handle Subscription Upgrades (Revise) — PayPal confirms the plan definition
        // changed, but it hasn't charged for it yet, so this only records the change as
        // pending. It's applied to sub_plan_id once PAYMENT.SALE.COMPLETED actually pays for it.
        if ($eventType === 'BILLING.SUBSCRIPTION.UPDATED') {
            $subscriptionId = $payload['resource']['id'] ?? null;
            $planId = $payload['resource']['plan_id'] ?? null;

            if ($subscriptionId && $planId) {
                $subscription = Subscription::where('paypal_subscription_id', $subscriptionId)->first();
                if ($subscription) {
                    $newPlan = SubscriptionPlan::where('paypal_plan_id', $planId)
                                               ->orWhere('paypal_yearly_plan_id', $planId)
                                               ->first();
                    if ($newPlan && $newPlan->sub_plan_id !== $subscription->sub_plan_id) {
                        $subscription->update([
                            'pending_sub_plan_id' => $newPlan->sub_plan_id
                        ]);
                        Log::info("Subscription $subscriptionId plan change to {$newPlan->sub_name} ($planId) scheduled for next billing date.");

                        $owner = $subscription->user;
                        if ($owner) {
                            Mail::to($owner->email)->send(new SubscriptionPlanChangedMail(
                                ownerName: $owner->firstname ?? $owner->username ?? 'there',
                                newPlanName: $newPlan->sub_name,
                                effectiveDate: $subscription->end_date ? $subscription->end_date->format('F j, Y') : 'your next billing cycle'
                            ));
                        }
                    }
                }
            }
        }

        // 3. Handle Cancellations
        if ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED' || $eventType === 'BILLING.SUBSCRIPTION.SUSPENDED') {
            $subscriptionId = $payload['resource']['id'] ?? null;

            if ($subscriptionId) {
                $sub = Subscription::where('paypal_subscription_id', $subscriptionId)->first();
                if ($sub) {
                    // If suspended (e.g. payment failed), immediately suspend. If just cancelled, leave current status intact.
                    $newStatus = $eventType === 'BILLING.SUBSCRIPTION.SUSPENDED' ? 'suspended' : $sub->status;
                    
                    $sub->update([
                        'status' => $newStatus,
                        'cancel_at_period_end' => true,
                        'pending_sub_plan_id' => null,
                        'pending_billing_cycle' => null
                    ]);
                    Log::info("Subscription $subscriptionId cancelled/suspended via Webhook.");

                    if ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED') {
                        $owner = $sub->user;
                        $plan = SubscriptionPlan::find($sub->sub_plan_id);
                        if ($owner && $plan) {
                            Mail::to($owner->email)->send(new SubscriptionCancelledMail(
                                ownerName: $owner->firstname ?? $owner->username ?? 'there',
                                planName: $plan->sub_name,
                                endDate: $sub->end_date ? $sub->end_date->format('F j, Y') : 'the end of your billing cycle'
                            ));
                        }
                    }
                }
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
