<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->service->listFor($this->user($request)));
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $result = $this->service->markRead($this->user($request), $id);

        return response()->json($result, $result['success'] ? 200 : 404);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return response()->json($this->service->markAllRead($this->user($request)));
    }

    // POS device tokens authenticate as PosDevice, which has no notifications.
    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'Unauthorized.');

        return $user;
    }
}
