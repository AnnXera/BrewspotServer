<?php

namespace Tests\Feature;

use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The first-login welcome modal: pending until the owner dismisses it, then gone for good.
 */
class OwnerWelcomeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }

        $this->owner = User::create([
            'firstname' => 'Ana',
            'lastname'  => 'Cruz',
            'email'     => 'owner@test.local',
            'status'    => 'active',
            'role_id'   => Role::where('role_name', 'Cafe Owner')->value('role_id'),
        ]);
    }

    public function test_a_new_owner_has_the_welcome_pending(): void
    {
        $this->assertTrue($this->welcomePending($this->owner));
    }

    public function test_dismissing_marks_the_owner_welcomed(): void
    {
        $this->withToken($this->owner->createToken('test')->plainTextToken)
            ->postJson('/api/owner/welcome/dismiss')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->owner->refresh();
        $this->assertNotNull($this->owner->welcomed_at);
        $this->assertFalse($this->welcomePending($this->owner));
    }

    public function test_dismissing_twice_keeps_the_first_time(): void
    {
        $token = $this->owner->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/owner/welcome/dismiss')->assertOk();
        $first = $this->owner->refresh()->welcomed_at;

        $this->travel(5)->minutes();
        $this->withToken($token)->postJson('/api/owner/welcome/dismiss')->assertOk();

        $this->assertTrue($first->equalTo($this->owner->refresh()->welcomed_at));
    }

    public function test_other_roles_cannot_dismiss(): void
    {
        $manager = User::create([
            'email'   => 'manager@test.local',
            'status'  => 'active',
            'role_id' => Role::where('role_name', 'Manager')->value('role_id'),
        ]);

        $this->withToken($manager->createToken('test')->plainTextToken)
            ->postJson('/api/owner/welcome/dismiss')
            ->assertForbidden();
    }

    private function welcomePending(User $user): bool
    {
        return (new UserResource($user))->toArray(Request::create('/'))['welcome_pending'];
    }
}
