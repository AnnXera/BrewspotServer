<?php

namespace App\Repository;

use App\Models\User;

class AuthRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::with('role')->where('email', $email)->first();
    }

    public function createToken(User $user): string
    {
        $user->tokens()->delete();

        return $user->createToken('auth_token')->plainTextToken;
    }
}