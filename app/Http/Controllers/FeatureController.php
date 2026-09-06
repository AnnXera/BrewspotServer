<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFeatureRequest;
use App\Http\Requests\UpdateFeatureRequest;
use App\Services\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    public function __construct(
        private readonly FeatureService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $features = $this->service->listFeatures($request->input('per_page', 50));

        return response()->json([
            'success'  => true,
            'features' => $features,
        ]);
    }

    public function active(): JsonResponse
    {
        $features = $this->service->listActiveFeatures();

        return response()->json([
            'success'  => true,
            'features' => $features,
        ]);
    }

    public function store(StoreFeatureRequest $request): JsonResponse
    {
        $result = $this->service->createFeature($request->validated());

        return response()->json($result, $result['success'] ? 201 : 422);
    }

    public function update(UpdateFeatureRequest $request, string $uuid): JsonResponse
    {
        $result = $this->service->updateFeature($uuid, $request->validated());

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $result = $this->service->deleteFeature($uuid);

        return response()->json($result, $result['success'] ? 200 : 404);
    }
}
