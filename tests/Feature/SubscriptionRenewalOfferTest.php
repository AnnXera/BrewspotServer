<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;

/**
 * What an owner whose term has run out is offered on their subscription page.
 */
class SubscriptionRenewalOfferTest extends FloorPlanTestCase
{
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscription = Subscription::where('user_id', $this->owner->user_id)->firstOrFail();
    }

    private function endTerm(): void
    {
        $this->subscription->update(['status' => 'expired', 'end_date' => Carbon::now()->subDay()]);
    }

    private function currentPlan()
    {
        return $this->api('GET', '/api/owner/subscription/current', $this->tokenFor($this->owner));
    }

    public function test_an_ended_free_trial_offers_no_plan_to_renew(): void
    {
        // The base fixture's plan is free, i.e. a trial.
        $this->endTerm();

        $this->currentPlan()
            ->assertJsonPath('renewal_offer.trial_ended', true)
            ->assertJsonPath('renewal_offer.plan', null);
    }

    public function test_an_ended_paid_plan_is_offered_for_renewal(): void
    {
        $paid = SubscriptionPlan::create(['sub_name' => 'Standard', 'price' => 499, 'max_branches' => 3, 'is_active' => true]);
        $this->subscription->update(['sub_plan_id' => $paid->sub_plan_id, 'billing_cycle' => 'monthly']);
        $this->endTerm();

        $this->currentPlan()
            ->assertJsonPath('renewal_offer.plan.uuid', $paid->uuid)
            ->assertJsonMissingPath('renewal_offer.trial_ended');
    }

    public function test_a_plan_booked_before_the_trial_ended_is_still_offered(): void
    {
        $paid = SubscriptionPlan::create(['sub_name' => 'Standard', 'price' => 499, 'max_branches' => 3, 'is_active' => true]);
        $this->subscription->update(['pending_sub_plan_id' => $paid->sub_plan_id, 'pending_billing_cycle' => 'monthly']);
        $this->endTerm();

        $this->currentPlan()
            ->assertJsonPath('renewal_offer.plan.uuid', $paid->uuid)
            ->assertJsonPath('renewal_offer.was_scheduled', true);
    }
}
