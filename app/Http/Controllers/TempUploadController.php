<?php

namespace App\Http\Controllers;

use App\Http\Requests\TempUploadRequest;
use App\Services\TempUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TempUploadController extends Controller
{
    public function __construct(
        private readonly TempUploadService $service
    ) {}

    public function upload(TempUploadRequest $request): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'path'    => $this->service->storeTempFile($request->file('file')),
            ]);
        } catch (\Exception $e) {
            Log::error('Temp upload failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'File upload failed.',
            ], 500);
        }
    }
}
