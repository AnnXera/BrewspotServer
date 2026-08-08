<?php

namespace Database\Seeders;

use App\Models\BranchDocument;
use App\Models\Cafe;
use App\Models\CafeBranch;
use App\Models\CafeDocument;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Requires OwnerSeeder to have run first.
     */
    public function run(): void
    {
        $owner = User::where('email', 'owner@brewspot.test')->first();

        if (! $owner) {
            $this->command->error('Owner not found. Run OwnerSeeder first: php artisan db:seed --class=OwnerSeeder');
            return;
        }

        $cafe = Cafe::where('user_id', $owner->user_id)->first();

        if (! $cafe) {
            $this->command->error('Cafe not found for this owner.');
            return;
        }

        $branch = CafeBranch::where('cafe_id', $cafe->cafe_id)->first();

        if (! $branch) {
            $this->command->error('Branch not found for this cafe.');
            return;
        }

        $userFolder   = "users/{$owner->uuid}";
        $cafeFolder   = "{$userFolder}/cafes/{$cafe->uuid}";
        $branchFolder = "{$cafeFolder}/branches/{$branch->uuid}";

        // 1. User government ID — blank PDF
        $userDocPath = $this->putBlankPdf("{$userFolder}/user_documents", 'national_id.pdf');

        UserDocument::firstOrCreate(
            ['user_id' => $owner->user_id, 'id_type' => 'national_id'],
            [
                'uuid' => (string) Str::uuid(),
                'file' => $userDocPath,
            ]
        );

        // 2. Cafe DTI document — blank PDF
        $cafeDocPath = $this->putBlankPdf("{$cafeFolder}/cafe_documents", 'dti_registration.pdf');

        CafeDocument::firstOrCreate(
            ['cafe_id' => $cafe->cafe_id, 'doc_type' => 'DTI'],
            [
                'file'          => $cafeDocPath,
                'registered_at' => now()->subMonths(6),
                'expired_at'    => now()->addYears(4),
            ]
        );

        // 3. Branch documents — blank PDFs (BIR, Mayor's Permit, Sanitary Permit)
        $branchDocs = [
            'BIR'             => 'bir_certificate.pdf',
            'mayors_permit'   => 'mayors_permit.pdf',
            'sanitary_permit' => 'sanitary_permit.pdf',
        ];

        foreach ($branchDocs as $docType => $filename) {
            $path = $this->putBlankPdf("{$branchFolder}/branch_documents", $filename);

            BranchDocument::firstOrCreate(
                ['branch_id' => $branch->branch_id, 'doc_type' => $docType],
                [
                    'file'          => $path,
                    'registered_at' => now()->subMonths(3),
                    'expired_at'    => now()->addYears(1),
                ]
            );
        }

        // 4. Cafe picture — real image copied to the PUBLIC disk
        $picturePath = $this->putBlankImage("{$branchFolder}/cafe_pictures", 'cafe_picture.jpg');

        if ($picturePath) {
            $branch->update(['cafe_picture' => $picturePath]);
        }

        $this->command->info('Placeholder documents seeded:');
        $this->command->info("  user doc:   {$userDocPath}");
        $this->command->info("  cafe doc:   {$cafeDocPath}");
        $this->command->info("  branch docs stored under: {$branchFolder}/branch_documents");
        $this->command->info("  cafe picture: " . ($picturePath ?: '(skipped — source file not found)'));
    }

    /**
     * Write a minimal, valid, blank one-page PDF to the private ('local') disk.
     */
    private function putBlankPdf(string $folder, string $filename): string
    {
        $pdfContent = <<<PDF
%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> /Contents 4 0 R >>
endobj
4 0 obj
<< /Length 44 >>
stream
BT /F1 18 Tf 72 720 Td (Placeholder Document) Tj ET
endstream
endobj
xref
0 5
0000000000 65535 f 
0000000009 00000 n 
0000000058 00000 n 
0000000115 00000 n 
0000000223 00000 n 
trailer
<< /Size 5 /Root 1 0 R >>
startxref
318
%%EOF
PDF;

        $path = "{$folder}/{$filename}";
        Storage::disk('local')->put($path, $pdfContent);

        return $path;
    }

    /**
     * Copy a real placeholder image (from database/seeders/assets/) to the 'public' disk.
     */
    private function putBlankImage(string $folder, string $filename): string
    {
        $sourcePath = database_path('seeders/assets/placeholder.jpg');

        if (! file_exists($sourcePath)) {
            $this->command->warn("Placeholder image not found at {$sourcePath} — skipping cafe picture.");
            return '';
        }

        // Preserve the actual extension of your source file
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $filename  = pathinfo($filename, PATHINFO_FILENAME) . '.' . $extension;

        $path = "{$folder}/{$filename}";

        Storage::disk('public')->put($path, file_get_contents($sourcePath));

        return $path;
    }
}