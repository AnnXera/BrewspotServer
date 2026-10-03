<?php

namespace Tests\Feature;

use App\Mail\SubscriptionCancelledMail;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Repository\SubscriptionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Owner cancellation: current plan stops renewing at period end, next plan is dropped,
 * and in neither case are the already-paid days taken away.
 */
class SubscriptionCancelPlanTest extends FloorPlanTestCase
{
    private Subscription $subscription;
    private SubscriptionPlan $paidPlan;
    private SubscriptionPlan $nextPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paidPlan = SubscriptionPlan::create(['sub_name' => 'Standard', 'price' => 499, 'max_branches' => 3, 'is_active' => true]);
        $this->nextPlan = SubscriptionPlan::create(['sub_name' => 'Premium', 'price' => 999, 'max_branches' => 10, 'is_active' => true]);

        // The base fixture's plan is free (counts as an unpaid term), so move the owner onto a paid one.
        $this->subscription = Subscription::where('user_id', $this->owner->user_id)->firstOrFail();
        $this->subscription->update([
            'sub_plan_id'   => $this->paidPlan->sub_plan_id,
            'billing_cycle' => 'monthly',
        ]);
    }

    private function cancelPlan(string $target)
    {
        return $this->api('POST', '/api/owner/subscriptions/cancel-plan', $this->tokenFor($this->owner), ['target' => $target]);
    }

    public function test_cancelling_current_plan_keeps_access_until_end_date(): void
    {
        $endDate = $this->subscription->end_date->copy();

        $this->cancelPlan('current')->assertOk()->assertJsonPath('success', true);

        $fresh = $this->subscription->fresh();
        $this->assertTrue($fresh->cancel_at_period_end);
        $this->assertSame('active', $fresh->status);
        $this->assertTrue($fresh->end_date->equalTo($endDate));

        // Still the owner's current plan, so feature access is unchanged.
        $this->api('GET', '/api/owner/subscription/current', $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonPath('subscription.cancel_at_period_end', true);

        Mail::assertQueued(SubscriptionCancelledMail::class);
    }

    public function test_cancelled_subscription_expires_only_after_end_date(): void
    {
        $this->cancelPlan('current')->assertOk();

        $this->artisan('subscriptions:expire');
        $this->assertSame('active', $this->subscription->fresh()->status);

        Carbon::setTestNow($this->subscription->end_date->copy()->addMinute());
        $this->artisan('subscriptions:expire');
        $this->assertSame('expired', $this->subscription->fresh()->status);
    }

    public function test_cancelling_current_plan_also_drops_booked_next_plan(): void
    {
        $this->subscription->update([
            'pending_sub_plan_id'   => $this->nextPlan->sub_plan_id,
            'pending_billing_cycle' => 'monthly',
        ]);

        $this->cancelPlan('current')->assertOk();

        $fresh = $this->subscription->fresh();
        $this->assertNull($fresh->pending_sub_plan_id);
        $this->assertNull($fresh->pending_billing_cycle);
    }

    public function test_cancelling_next_plan_leaves_current_plan_renewing(): void
    {
        $this->subscription->update([
            'pending_sub_plan_id'   => $this->nextPlan->sub_plan_id,
            'pending_billing_cycle' => 'yearly',
        ]);

        $this->cancelPlan('next')->assertOk()->assertJsonPath('success', true);

        $fresh = $this->subscription->fresh();
        $this->assertNull($fresh->pending_sub_plan_id);
        $this->assertFalse($fresh->cancel_at_period_end);
    }

    public function test_cancelling_next_plan_without_one_is_rejected(): void
    {
        $this->cancelPlan('next')->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_cancelling_twice_is_rejected(): void
    {
        $this->cancelPlan('current')->assertOk();
        $this->cancelPlan('current')->assertStatus(422);
    }

    public function test_free_trial_cannot_be_cancelled(): void
    {
        $this->subscription->update(['billing_cycle' => 'trial']);

        $this->cancelPlan('current')->assertStatus(422);
        $this->assertFalse($this->subscription->fresh()->cancel_at_period_end);
    }

    public function test_invalid_target_is_rejected(): void
    {
        $this->cancelPlan('everything')->assertStatus(422);
    }

    public function test_resume_undoes_the_cancellation(): void
    {
        $this->cancelPlan('current')->assertOk();

        $this->api('POST', '/api/owner/subscriptions/resume', $this->tokenFor($this->owner))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertFalse($this->subscription->fresh()->cancel_at_period_end);
    }

    public function test_resume_without_a_cancellation_is_rejected(): void
    {
        $this->api('POST', '/api/owner/subscriptions/resume', $this->tokenFor($this->owner))->assertStatus(422);
    }

    public function test_cannot_schedule_a_plan_change_while_cancelled(): void
    {
        $this->cancelPlan('current')->assertOk();

        $this->api('POST', '/api/owner/subscriptions/schedule-change', $this->tokenFor($this->owner), [
            'plan_uuid'     => $this->nextPlan->uuid,
            'billing_cycle' => 'monthly',
        ])->assertStatus(422);

        $this->assertNull($this->subscription->fresh()->pending_sub_plan_id);
    }

    public function test_cancelled_terms_are_not_sent_renewal_reminders(): void
    {
        $this->subscription->update(['end_date' => now()->addHours(12)]);
        $repo = app(SubscriptionRepository::class);

        $this->assertCount(1, $repo->findRenewalOpenUnnotified());
        $this->assertCount(1, $repo->findExpiringWithinDays(3));

        $this->cancelPlan('current')->assertOk();

        $this->assertCount(0, $repo->findRenewalOpenUnnotified());
        $this->assertCount(0, $repo->findExpiringWithinDays(3));
    }

    public function test_other_roles_cannot_cancel(): void
    {
        $this->api('POST', '/api/owner/subscriptions/cancel-plan', $this->tokenFor($this->manager), ['target' => 'current'])
            ->assertForbidden();
    }
}
