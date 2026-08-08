<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class FreshWithStorage extends Command
{
    protected $signature = 'app:fresh {--seed}';

    protected $description = 'Run migrate:fresh and wipe uploaded files from private/public storage';

    public function handle(): int
    {
        $this->clearDisk(storage_path('app/private'));
        $this->clearDisk(storage_path('app/public'));

        $this->call('migrate:fresh', [
            '--seed' => $this->option('seed'),
        ]);

        $this->info('Storage (private + public) cleared, then migrate:fresh' . ($this->option('seed') ? ' + seed' : '') . ' ran.');

        return self::SUCCESS;
    }

    private function clearDisk(string $path): void
    {
        if (! File::exists($path)) {
            return;
        }

        foreach (File::directories($path) as $dir) {
            File::deleteDirectory($dir);
        }

        foreach (File::files($path) as $file) {
            // keep .gitignore so the empty folder stays tracked in git.
            if ($file->getFilename() !== '.gitignore') {
                File::delete($file->getPathname());
            }
        }
    }
}