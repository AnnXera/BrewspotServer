<?php

namespace App\Console\Commands;

use App\Models\UserDocument;
use App\Models\CafeDocument;
use App\Models\BranchDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateDocumentsToPrivate extends Command
{
    protected $signature = 'documents:migrate-to-private
                            {--dry-run : Show what would happen without moving anything}';

    protected $description = 'Move existing user/cafe/branch documents from the public disk to the private (local) disk, and update their stored paths.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no files will be moved, no DB rows will be updated.');
        }

        $this->migrateModel(UserDocument::class, 'user_doc_id', $dryRun);
        $this->migrateModel(CafeDocument::class, 'cafe_doc_id', $dryRun);
        $this->migrateModel(BranchDocument::class, 'branch_doc_id', $dryRun);

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function migrateModel(string $modelClass, string $primaryKey, bool $dryRun): void
    {
        $label = class_basename($modelClass);
        $rows  = $modelClass::all();

        $this->info("Processing {$rows->count()} {$label} record(s)...");

        $moved   = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($rows as $row) {
            $path = $row->file;

            if (! $path) {
                $skipped++;
                continue;
            }

            // Already migrated (not on public disk anymore)?
            if (! Storage::disk('public')->exists($path)) {
                if (Storage::disk('local')->exists($path)) {
                    $skipped++; // already private, nothing to do
                } else {
                    $this->error("  [{$row->{$primaryKey}}] File missing on both disks: {$path}");
                    $missing++;
                }
                continue;
            }

            $this->line("  [{$row->{$primaryKey}}] {$path}");

            if ($dryRun) {
                $moved++;
                continue;
            }

            // Read from public, write to local, then delete from public
            $contents = Storage::disk('public')->get($path);
            Storage::disk('local')->put($path, $contents);
            Storage::disk('public')->delete($path);

            // Path string itself doesn't change (same relative path,
            // just a different disk) — no DB update needed since we
            // don't store the disk name, only the path.

            $moved++;
        }

        $this->info("  {$label}: moved={$moved}, skipped={$skipped}, missing={$missing}");
    }
}