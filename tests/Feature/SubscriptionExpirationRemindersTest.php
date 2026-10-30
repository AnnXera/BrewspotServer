<?php

namespace Tests\Feature;

use App\Jobs\SendSubscriptionNotice;
use App\Mail\SubscriptionExpiringMail;
use App\Models\Subscription;
use App\Models\SubscriptionNotice;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * "Ending soon" reminders go out 7, 3 and 1 day(s) before the term ends, each once,
 * by email and in the owner's notification bell.
 */
class SubscriptionExpirationRemindersTest extends FloorPlanTestCase
{
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscription = Subscription::where('user_id', $this->owner->user_id)->firstOrFail();
    }

    private function runReminders(): void
    {
        $this->artisan('subscriptions:send-expiration-reminders')->assertSuccessful();
    }

    private function bellCount(): int
    {
        return $this->owner->notifications()->count();
    }

    private function sentKinds(): array
    {
        return $this->subscription->notices()->orderBy('kind')->pluck('kind')->all();
    }

    public function test_each_reminder_is_sent_once_by_email_and_bell(): void
    {
        $endDate = now()->addDays(7)->subHour();
        $this->subscription->update(['end_date' => $endDate]);

        $this->runReminders();
        $this->runReminders();
        Mail::assertSent(SubscriptionExpiringMail::class, 1);
        $this->assertSame(1, $this->bellCount());

        Carbon::setTestNow($endDate->copy()->subDays(3)->addHour());
        $this->runReminders();
        $this->runReminders();
        Mail::assertSent(SubscriptionExpiringMail::class, 2);
        $this->assertSame(2, $this->bellCount());

        Carbon::setTestNow($endDate->copy()->subDay()->addHour());
        $this->runReminders();
        $this->runReminders();
        Mail::assertSent(SubscriptionExpiringMail::class, 3);
        $this->assertSame(3, $this->bellCount());

        $this->assertSame(['expiring_1d', 'expiring_3d', 'expiring_7d'], $this->sentKinds());
    }

    public function test_one_day_reminder_says_renewal_is_open(): void
    {
        $this->subscription->update(['end_date' => now()->addHours(12)]);

        $this->runReminders();

        Mail::assertSent(SubscriptionExpiringMail::class, fn (SubscriptionExpiringMail $mail) => $mail->renewalOpen);
        $this->assertStringContainsString('Renewal is open', $this->owner->notifications()->first()->data['body']);
    }

    public function test_term_already_inside_a_window_skips_the_larger_ones(): void
    {
        $this->subscription->update(['end_date' => now()->addDays(2)]);

        $this->runReminders();

        Mail::assertSent(SubscriptionExpiringMail::class, 1);
        $this->assertSame(['expiring_3d'], $this->sentKinds());
    }

    public function test_trials_get_reminders_to_choose_a_plan(): void
    {
        $trial = SubscriptionPlan::create(['sub_name' => 'Free Trial', 'price' => 0, 'max_branches' => 1, 'is_active' => true]);
        $this->subscription->update([
            'sub_plan_id'   => $trial->sub_plan_id,
            'billing_cycle' => 'trial',
            'end_date'      => now()->addDays(3)->subHour(),
        ]);

        $this->runReminders();

        Mail::assertSent(SubscriptionExpiringMail::class, fn (SubscriptionExpiringMail $mail) => $mail->renewPlanName === null);
        $this->assertStringContainsString('Choose a plan', $this->owner->notifications()->first()->data['body']);
    }

    public function test_gateway_managed_and_cancelled_terms_are_skipped(): void
    {
        $this->subscription->update(['end_date' => now()->addDays(2), 'gateway_subscription_id' => 'sub_gateway']);
        $this->runReminders();

        $this->subscription->update(['gateway_subscription_id' => null, 'cancel_at_period_end' => true]);
        $this->runReminders();

        Mail::assertNotSent(SubscriptionExpiringMail::class);
        $this->assertSame(0, $this->bellCount());
    }

    public function test_failed_email_releases_the_claim_and_is_sent_again(): void
    {
        $this->subscription->update(['end_date' => now()->addDays(2)]);
        $this->runReminders();

        $job = new SendSubscriptionNotice($this->subscription->sub_id, 'expiring_3d', $this->owner->email,
            new SubscriptionExpiringMail('Owner', 'Pro', 'October 7, 2026', 2));
        $job->failed(new RuntimeException('SMTP down'));

        $this->assertSame([], $this->sentKinds());

        $this->runReminders();

        Mail::assertSent(SubscriptionExpiringMail::class, 2);
        $this->assertSame(['expiring_3d'], $this->sentKinds());
        // The bell notice's dedupe key keeps the retry from posting it twice.
        $this->assertSame(1, $this->bellCount());
    }
}
