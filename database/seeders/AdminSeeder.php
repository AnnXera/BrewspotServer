<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use App\Models\User;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@brewspot.com'], 
            [
                'uuid'              => (string) Str::uuid(),
                'firstname'         => 'Xera',
                'middlename'        => null,
                'lastname'          => 'Jupiter',
                'username'          => 'brewspot_admin',
                'password_hash'     => Hash::make('Brewspot1'), 
                'phone_number'      => '09123456789',
                'role_id'           => 1,
                'status'            => 'active',
                'email_verified_at' => now(), 
            ]
        );
    }
}