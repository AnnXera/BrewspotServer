<?php

namespace App\Http\Controllers;

use App\Services\TempUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TempUploadController extends Controller
{
    protected TempUploadService $tempUploadService;

    public function __construct(TempUploadService $tempUploadService)
    {
        $this->tempUploadService = $tempUploadService;
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:5120|mimes:jpg,jpeg,png,pdf',
        ]);

        try {
            $path = $this->tempUploadService->storeTempFile($request->file('file'));
            return response()->json([
                'success' => true,
                'path' => $path
            ]);
        } catch (\Exception $e) {
            Log::error('Temp upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'File upload failed.'
            ], 500);
        }
    }
}
