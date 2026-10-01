<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\CafeOpeningHour;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The cafe's opening hours
 */
class OpeningHoursTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Cafe $cafe;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['role_name' => 'Admin']);
        Role::create(['role_name' => 'Cafe Owner']);

        $this->owner = User::create([
            'firstname'         => 'Test',
            'lastname'          => 'Owner',
            'email'             => 'owner@test.local',
            'status'            => 'active',
            'email_verified_at' => now(),
            'role_id'           => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);
        $this->cafe  = Cafe::create(['user_id' => $this->owner->user_id, 'cafe_name' => 'Test Cafe']);
        $this->token = $this->owner->createToken('test')->plainTextToken;
    }

    public function test_new_cafe_gets_the_default_week_in_order(): void
    {
        $this->api('GET', '/api/owner/opening-hours')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonPath('data.0', [
                'day_of_week' => 'Monday', 'is_closed' => false, 'is_24_hours' => false,
                'open_time' => '09:00', 'close_time' => '17:00', 'closes_after_midnight' => false,
            ])
            ->assertJsonPath('data.6.day_of_week', 'Sunday')
            ->assertJsonPath('data.6.is_closed', true)
            ->assertJsonPath('data.6.open_time', null);
    }

    public function test_cafe_without_hours_gets_defaults_on_first_load(): void
    {
        CafeOpeningHour::where('cafe_id', $this->cafe->cafe_id)->delete();

        $this->api('GET', '/api/owner/opening-hours')->assertOk()->assertJsonCount(7, 'data');
        $this->assertSame(7, CafeOpeningHour::where('cafe_id', $this->cafe->cafe_id)->count());
    }

    public function test_saves_closed_24_hour_and_after_midnight_days(): void
    {
        $week = $this->week();
        $week[0] = ['day_of_week' => 'Monday', 'is_closed' => false, 'is_24_hours' => true, 'open_time' => '08:00', 'close_time' => '20:00'];
        $week[4] = ['day_of_week' => 'Friday', 'is_closed' => false, 'is_24_hours' => false, 'open_time' => '18:00', 'close_time' => '02:00'];
        $week[5] = ['day_of_week' => 'Saturday', 'is_closed' => true, 'open_time' => '08:00', 'close_time' => '20:00'];
        $week[6] = ['day_of_week' => 'Sunday', 'is_closed' => false, 'open_time' => '10:00:00', 'close_time' => '15:30:00'];

        $this->api('PUT', '/api/owner/opening-hours', ['hours' => $week])
            ->assertOk()
            ->assertJsonPath('data.0.is_24_hours', true)
            ->assertJsonPath('data.0.open_time', null)
            ->assertJsonPath('data.4.closes_after_midnight', true)
            ->assertJsonPath('data.4.close_time', '02:00')
            ->assertJsonPath('data.5.is_closed', true)
            ->assertJsonPath('data.5.open_time', null)
            ->assertJsonPath('data.6.close_time', '15:30');

        $this->api('GET', '/api/owner/opening-hours')->assertJsonPath('data.4.open_time', '18:00');
    }

    public function test_open_days_need_two_different_times(): void
    {
        $week = $this->week();
        $week[1] = ['day_of_week' => 'Tuesday', 'is_closed' => false, 'open_time' => null, 'close_time' => '17:00'];
        $week[2] = ['day_of_week' => 'Wednesday', 'is_closed' => false, 'open_time' => '09:00', 'close_time' => '09:00'];

        $this->api('PUT', '/api/owner/opening-hours', ['hours' => $week])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['hours.1.open_time', 'hours.2.close_time']);

        $week = $this->week();
        $week[6]['day_of_week'] = 'Monday';

        $this->api('PUT', '/api/owner/opening-hours', ['hours' => $week])
            ->assertStatus(422)->assertJsonValidationErrors('hours.6.day_of_week');
    }

    private function week(): array
    {
        return array_map(fn ($day) => [
            'day_of_week' => $day, 'is_closed' => false, 'is_24_hours' => false, 'open_time' => '07:00', 'close_time' => '22:00',
        ], CafeOpeningHour::DAYS);
    }

    private function api(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$this->token}")->json($method, $uri, $data);
    }
}
