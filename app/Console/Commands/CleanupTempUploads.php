<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:cleanup-temp-uploads')]
#[Description('Command description')]
class CleanupTempUploads extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $files = $disk->files('temp');
        $now = now()->timestamp;
        $deletedCount = 0;

        foreach ($files as $file) {
            // Check if file is older than 24 hours (86400 seconds)
            if ($now - $disk->lastModified($file) > 86400) {
                $disk->delete($file);
                $deletedCount++;
            }
        }

        $this->info("Cleaned up {$deletedCount} temporary file(s).");
    }
}
