<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * For services that return an `http` key alongside `success`: uses it as
     * the status code and strips it from the body.
     */
    protected function respond(array $result, int $successStatus = 200, int $failureStatus = 422): JsonResponse
    {
        $status = $result['http'] ?? ($result['success'] ? $successStatus : $failureStatus);
        unset($result['http']);

        return response()->json($result, $status);
    }
}
