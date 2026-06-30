<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['role_id' => 1, 'role_name' => 'Admin', 'uuid' => (string) Str::uuid()],
            ['role_id' => 2, 'role_name' => 'Cafe Owner', 'uuid' => (string) Str::uuid()],
            ['role_id' => 3, 'role_name' => 'Manager', 'uuid' => (string) Str::uuid()],
            ['role_id' => 4, 'role_name' => 'Cashier', 'uuid' => (string) Str::uuid()],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['role_id' => $role['role_id']],
                $role
            );
        }
    }
}