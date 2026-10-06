<?php

namespace Database\Seeders;

use App\Models\CafeBranch;
use App\Models\CafeStaff;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ManagerSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::firstOrCreate(
            ['role_name' => 'Manager'],
            ['uuid' => (string) Str::uuid()]
        );

        // Assigned to the main branch created by OwnerSeeder.
        $branch = CafeBranch::where('cafe_email', 'branch@brewhaven.test')->first();

        if (! $branch) {
            $this->command->warn('Main branch not found. Run OwnerSeeder first.');
            return;
        }

        // Active, verified manager with password and PIN already set.
        $manager = User::firstOrCreate(
            ['email' => 'manager@brewspot.test'],
            [
                'uuid'              => (string) Str::uuid(),
                'firstname'         => 'Maria',
                'middlename'        => null,
                'lastname'          => 'Santos',
                'username'          => 'mariasantos',
                'password_hash'     => Hash::make('Password123!'),
                'phone_number'      => '+639171234568',
                'address'           => '789 Quirino Ave, Davao City',
                'email_verified_at' => Carbon::now(),
                'status'            => 'active',
                'role_id'           => $role->role_id,
            ]
        );

        // PIN columns are not fillable (written through StaffPinService in the app).
        if (! $manager->hasPin()) {
            $manager->forceFill([
                'pin_hash'            => Hash::make('123456'),
                'pin_failed_attempts' => 0,
                'pin_locked_at'       => null,
                'pin_must_change'     => false,
            ])->save();
        }

        // DatabaseSeeder runs WithoutModelEvents, so CafeStaff's creating hook
        // doesn't fire: set uuid explicitly.
        CafeStaff::firstOrCreate(
            ['user_id' => $manager->user_id, 'branch_id' => $branch->branch_id],
            [
                'uuid'              => (string) Str::uuid(),
                'employment_status' => CafeStaff::STATUS_ACTIVE,
                'hired_at'          => Carbon::today(),
            ]
        );

        $this->command->info('Test manager ready:');
        $this->command->info('  email:    manager@brewspot.test');
        $this->command->info('  password: Password123!');
        $this->command->info('  PIN:      123456');
    }
}
