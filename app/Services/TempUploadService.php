<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class TempUploadService
{
    /**
     * Store a file temporarily and return its path.
     */
    public function storeTempFile(UploadedFile $file): string
    {
        return $file->store('temp', 'local');
    }
}
