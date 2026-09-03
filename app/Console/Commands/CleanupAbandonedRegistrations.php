<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupAbandonedRegistrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'registrations:cleanup-abandoned {--hours=1 : Number of hours before an incomplete registration is considered abandoned}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete incomplete or abandoned user registrations (email_unverified, filling_application)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $cutoff = Carbon::now()->subHours($hours);

        $this->info("Scanning for abandoned registrations older than {$hours} hour(s) (before {$cutoff->toDateTimeString()})...");

        $abandonedUsers = User::whereIn('status', ['email_unverified', 'filling_application'])
            ->where(function ($query) use ($cutoff) {
                $query->where('updated_at', '<', $cutoff)
                      ->orWhere(function ($q) use ($cutoff) {
                          $q->whereNull('updated_at')
                            ->where('created_at', '<', $cutoff);
                      });
            })
            ->get();

        $count = $abandonedUsers->count();

        if ($count === 0) {
            $this->info('No abandoned registrations found.');
            return self::SUCCESS;
        }

        $deletedCount = 0;

        foreach ($abandonedUsers as $user) {
            try {
                DB::transaction(function () use ($user) {
                    $uuid = $user->uuid;
                    $email = $user->email;
                    $userId = $user->user_id;

                    // 1. Remove user storage folders if they exist
                    if (!empty($uuid)) {
                        Storage::disk('local')->deleteDirectory("users/{$uuid}");
                        Storage::disk('public')->deleteDirectory("users/{$uuid}");
                    }

                    // 2. Cascade delete will remove related verification_codes, cafes, etc.
                    $user->delete();

                    Log::channel('registration')->info('Abandoned registration deleted.', [
                        'user_id' => $userId,
                        'uuid'    => $uuid,
                        'email'   => $email,
                        'status'  => $user->status,
                    ]);
                });

                $deletedCount++;
            } catch (\Throwable $e) {
                Log::channel('registration')->error('Failed to delete abandoned user registration.', [
                    'user_id' => $user->user_id,
                    'uuid'    => $user->uuid,
                    'error'   => $e->getMessage(),
                ]);

                $this->error("Failed to delete user ID {$user->user_id}: {$e->getMessage()}");
            }
        }

        $this->info("Successfully deleted {$deletedCount} out of {$count} abandoned registration(s).");

        return self::SUCCESS;
    }
}
