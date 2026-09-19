<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use App\Models\User;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\Payment;
use Illuminate\Support\Str;

class PayPalSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_receive_webhook_and_process_payment()
    {
        // Setup data
        $user = User::factory()->create();
        $plan = SubscriptionPlan::create([
            'uuid' => (string) Str::uuid(),
            'sub_name' => 'Premium',
            'price' => 599.00,
            'yearly_price' => 5990.00,
            'max_branches' => 1,
        ]);

        $subscription = Subscription::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->user_id,
            'sub_plan_id' => $plan->sub_plan_id,
            'paypal_subscription_id' => 'I-FAKE12345',
            'status' => 'pending',
            'start_date' => now(),
            'end_date' => now(),
        ]);

        // Fake the HTTP requests to PayPal
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
            '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
        ]);

        // Send a fake webhook request
        $response = $this->postJson('/api/webhooks/paypal', [
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'resource' => [
                'billing_agreement_id' => 'I-FAKE12345',
                'amount' => [
                    'total' => '599.00',
                    'currency' => 'PHP'
                ]
            ]
        ]);

        $response->assertStatus(200);

        // Check if Payment was created
        $this->assertDatabaseHas('payments', [
            'gateway_transaction_id' => 'I-FAKE12345',
            'amount' => 599.00,
            'status' => 'completed'
        ]);

        // Check if subscription was updated
        $this->assertDatabaseHas('subscriptions', [
            'paypal_subscription_id' => 'I-FAKE12345',
            'status' => 'active'
        ]);
        
        $subscription->refresh();
        $this->assertTrue($subscription->end_date->gt(now()->addDays(20))); // Should be +1 month
    }
}
