<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Facades\URL;

/**
 * What happens when a checkout doesn't go through: the owner backs out of PayMongo's page, or
 * asks for a billing term the plan can't be sold on.
 */
class SubscriptionCheckoutFailureTest extends FloorPlanTestCase
{
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = SubscriptionPlan::create(['sub_name' => 'Standard', 'price' => 499, 'yearly_price' => 0, 'max_branches' => 3, 'is_active' => true]);
    }

    // A checkout as SubscriptionCheckoutService leaves it once PayMongo has opened the session.
    private function pendingCheckout(string $sessionId = 'cs_test_123', ?string $intentId = null): array
    {
        $subscription = app(SubscriptionRepository::class)->createPending($this->owner->user_id, $this->plan, 'monthly');

        $payment = Payment::create([
            'user_id'                   => $this->owner->user_id,
            'payable_type'              => Subscription::class,
            'payable_id'                => $subscription->sub_id,
            'amount'                    => 49900,
            'payment_method_type'       => 'paymongo_checkout',
            'gateway_transaction_id'    => $sessionId,
            'gateway_payment_intent_id' => $intentId,
            'status'                    => 'pending',
        ]);

        return [$subscription, $payment];
    }

    public function test_backing_out_of_checkout_closes_it_and_returns_the_owner_to_the_app(): void
    {
        [$subscription, $payment] = $this->pendingCheckout();

        // A plain browser redirect from PayMongo: no API token, so the route must not need one.
        $this->get(URL::signedRoute('payment.cancel', ['subscription' => $subscription->uuid]))
            ->assertRedirect(config('services.paymongo.cancel_url'));

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('cancelled', $subscription->fresh()->status);
    }

    public function test_an_edited_cancel_link_closes_nothing(): void
    {
        [$mine] = $this->pendingCheckout('cs_test_mine');
        [$other, $otherPayment] = $this->pendingCheckout('cs_test_other');

        // Swap in someone else's subscription: the signature no longer matches.
        $url = str_replace($mine->uuid, $other->uuid, URL::signedRoute('payment.cancel', ['subscription' => $mine->uuid]));

        $this->get($url)->assertRedirect(config('services.paymongo.cancel_url'));

        $this->assertSame('pending', $otherPayment->fresh()->status);
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_a_cancel_link_leaves_a_checkout_that_was_already_paid_alone(): void
    {
        [$subscription, $payment] = $this->pendingCheckout();
        $payment->update(['status' => 'succeeded']);
        app(SubscriptionRepository::class)->activate($subscription);

        $this->get(URL::signedRoute('payment.cancel', ['subscription' => $subscription->uuid]))
            ->assertRedirect(config('services.paymongo.cancel_url'));

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    private function age(Payment $payment, int $hours): void
    {
        $payment->forceFill(['created_at' => now()->subHours($hours)])->saveQuietly();
    }

    public function test_cleanup_closes_checkouts_left_unfinished_for_a_day(): void
    {
        [$stale, $stalePayment] = $this->pendingCheckout('cs_test_stale');
        [$fresh, $freshPayment] = $this->pendingCheckout('cs_test_fresh');
        [$paid, $paidPayment] = $this->pendingCheckout('cs_test_paid');
        $this->age($stalePayment, 25);
        $this->age($freshPayment, 1);
        $paidPayment->update(['status' => 'succeeded']);
        $this->age($paidPayment, 25);

        $this->artisan('subscriptions:close-stale-checkouts', ['--hours' => 24])->assertSuccessful();

        $this->assertSame('failed', $stalePayment->fresh()->status);
        $this->assertSame('cancelled', $stale->fresh()->status);
        $this->assertSame('pending', $freshPayment->fresh()->status);
        $this->assertSame('pending', $fresh->fresh()->status);
        $this->assertSame('succeeded', $paidPayment->fresh()->status);
    }

    public function test_a_payment_confirmed_after_cleanup_still_activates_the_plan(): void
    {
        [$subscription, $payment] = $this->pendingCheckout('cs_test_late');
        $this->age($payment, 25);
        $this->artisan('subscriptions:close-stale-checkouts', ['--hours' => 24]);

        // PayMongo's checkout_session.payment.paid arriving late (the handler past the signature check).
        $handler = new \ReflectionMethod(\App\Services\PayMongoWebhookService::class, 'handleCheckoutPaid');
        $handler->invoke(app(\App\Services\PayMongoWebhookService::class), ['id' => 'cs_test_late', 'attributes' => []]);

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    // A payment.* webhook as PayMongo sends it for a GCash payment inside a checkout: no
    // metadata, just the checkout's payment intent (the handler past the signature check).
    private function paymentEvent(string $outcome, string $intentId): void
    {
        $handler = new \ReflectionMethod(\App\Services\PayMongoWebhookService::class, 'handlePaymentEvent');
        $handler->invoke(app(\App\Services\PayMongoWebhookService::class), [
            'id'         => 'pay_test_' . $outcome,
            'attributes' => ['metadata' => null, 'payment_intent_id' => $intentId, 'source' => ['type' => 'gcash']],
        ], $outcome);
    }

    public function test_a_failed_e_wallet_payment_marks_its_checkout_failed(): void
    {
        [$subscription, $payment] = $this->pendingCheckout('cs_test_gcash', 'pi_test_gcash');

        $this->paymentEvent('failed', 'pi_test_gcash');

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('cancelled', $subscription->fresh()->status);
    }

    public function test_retrying_after_a_failed_e_wallet_payment_still_activates_the_plan(): void
    {
        [$subscription, $payment] = $this->pendingCheckout('cs_test_retry', 'pi_test_retry');

        $this->paymentEvent('failed', 'pi_test_retry');
        $this->paymentEvent('paid', 'pi_test_retry');

        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_a_payment_event_for_an_unknown_intent_changes_nothing(): void
    {
        [$subscription, $payment] = $this->pendingCheckout('cs_test_known', 'pi_test_known');

        $this->paymentEvent('failed', 'pi_test_someone_else');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending', $subscription->fresh()->status);
    }

    public function test_yearly_checkout_is_refused_for_a_plan_without_a_yearly_price(): void
    {
        $before = Subscription::count();

        $this->api('POST', '/api/owner/subscriptions/checkout', $this->tokenFor($this->owner), [
            'plan_uuid'     => $this->plan->uuid,
            'billing_cycle' => 'yearly',
        ])->assertJsonPath('success', false);

        // Refused before anything was recorded or sent to PayMongo.
        $this->assertSame($before, Subscription::count());
    }
}
