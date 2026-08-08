<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StorageController extends Controller
{
    /**
     * Serve files from the public disk through Laravel so middleware (CORS, auth if desired)
     * and headers can be applied. Public files are intended to be accessible without auth.
     *
     * GET /api/storage/public/{path}
     */
    public function publicFile(Request $request, string $path)
    {
        if (! Storage::disk('public')->exists($path)) {
            abort(404, 'File not found.');
        }

        $response = Storage::disk('public')->response($path);
        // Encourage client caching for a day
        $response->headers->set('Cache-Control', 'public, max-age=86400');

        return $response;
    }
}
