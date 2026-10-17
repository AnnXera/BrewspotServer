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

class CashierSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::firstOrCreate(
            ['role_name' => 'Cashier'],
            ['uuid' => (string) Str::uuid()]
        );

        // Assigned to the main branch created by OwnerSeeder.
        $branch = CafeBranch::where('cafe_email', 'branch@brewhaven.test')->first();

        if (! $branch) {
            $this->command->warn('Main branch not found. Run OwnerSeeder first.');
            return;
        }

        // Cashiers don't log into the dashboard: they unlock the POS with name + PIN.
        $cashier = User::firstOrCreate(
            ['email' => 'cashier@brewspot.test'],
            [
                'uuid'              => (string) Str::uuid(),
                'firstname'         => 'Juan',
                'middlename'        => null,
                'lastname'          => 'Dela Cruz',
                'username'          => 'juandelacruz',
                'password_hash'     => Hash::make('Password123!'),
                'phone_number'      => '+639171234569',
                'address'           => '456 Roxas Ave, Davao City',
                'email_verified_at' => Carbon::now(),
                'status'            => 'active',
                'role_id'           => $role->role_id,
            ]
        );

        // PIN columns are not fillable (written through StaffPinService in the app).
        if (! $cashier->hasPin()) {
            $cashier->forceFill([
                'pin_hash'            => Hash::make('123456'),
                'pin_failed_attempts' => 0,
                'pin_locked_at'       => null,
                'pin_must_change'     => false,
            ])->save();
        }

        // DatabaseSeeder runs WithoutModelEvents, so CafeStaff's creating hook
        // doesn't fire: set uuid explicitly.
        CafeStaff::firstOrCreate(
            ['user_id' => $cashier->user_id, 'branch_id' => $branch->branch_id],
            [
                'uuid'              => (string) Str::uuid(),
                'employment_status' => CafeStaff::STATUS_ACTIVE,
                'hired_at'          => Carbon::today(),
            ]
        );

        $this->command->info('Test cashier ready:');
        $this->command->info('  name: Juan Dela Cruz');
        $this->command->info('  PIN:  123456');
    }
}
