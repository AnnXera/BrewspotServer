<?php

namespace Tests\Feature;

use App\Mail\LoginCodeMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Platform admins sign in with email and password only; every other role still gets
 * an emailed 2FA code before a token is issued.
 */
class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        foreach (['Admin', 'Cafe Owner', 'Manager', 'Cashier', 'Staff'] as $name) {
            Role::create(['role_name' => $name]);
        }
    }

    private function makeUser(string $role, array $overrides = []): User
    {
        return User::create([
            'firstname'         => 'Test',
            'lastname'          => $role,
            'email'             => strtolower(str_replace(' ', '', $role)) . '@test.local',
            'password_hash'     => Hash::make('secret123'),
            'status'            => 'active',
            'email_verified_at' => now(),
            'role_id'           => Role::where('role_name', $role)->value('role_id'),
            ...$overrides,
        ]);
    }

    public function test_admin_gets_a_token_without_a_code(): void
    {
        $admin = $this->makeUser('Admin');

        $token = $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonMissingPath('requires_2fa')
            ->assertJsonPath('role', 'Admin')
            ->assertJsonPath('user.email', $admin->email)
            ->json('token');

        $this->assertNotEmpty($token);
        Mail::assertNotSent(LoginCodeMail::class);
        $this->assertDatabaseMissing('verification_codes', ['user_id' => $admin->user_id]);

        // The token works on admin routes.
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/owners/stats')->assertOk();
    }

    public function test_admin_still_needs_the_right_password_and_an_active_account(): void
    {
        $admin = $this->makeUser('Admin');

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'wrong-pass'])
            ->assertUnauthorized()->assertJsonMissingPath('token');

        $admin->update(['status' => 'suspended']);
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'secret123'])
            ->assertUnauthorized()->assertJsonMissingPath('token');

        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_other_roles_still_get_an_emailed_code(): void
    {
        foreach (['Cafe Owner', 'Manager'] as $role) {
            $user = $this->makeUser($role);

            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])
                ->assertOk()
                ->assertJsonPath('requires_2fa', true)
                ->assertJsonMissingPath('token');

            Mail::assertSent(LoginCodeMail::class, fn ($mail) => $mail->hasTo($user->email));
            $this->assertSame(0, $user->tokens()->count());
        }
    }
}
