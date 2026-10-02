<?php

namespace App\Http\Controllers\SavedFilters;

use App\Http\Controllers\Controller;
use App\Services\SavedFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreSavedFilterController extends Controller
{
    public function __invoke(Request $request, SavedFilterService $service, string $scope): JsonResponse
    {
        $data = $service->validate($request, $scope);
        $isDefault = $data['is_default'] ?? false;
        unset($data['is_default']);
        $savedFilter = $service->owned($request->user(), $scope)->create([
            'organization_id' => $request->user()->organization_id,
            'user_id' => $request->user()->id,
            'scope' => $scope,
            ...$data,
        ]);

        if ($isDefault) {
            $service->setDefault($request->user(), $scope, $savedFilter->id);
        }

        return response()->json($service->payload($savedFilter, $request->user()), 201);
    }
}
