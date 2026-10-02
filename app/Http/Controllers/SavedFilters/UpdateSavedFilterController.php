<?php

namespace App\Http\Controllers\SavedFilters;

use App\Http\Controllers\Controller;
use App\Services\SavedFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpdateSavedFilterController extends Controller
{
    public function __invoke(Request $request, SavedFilterService $service, string $scope, string $id): JsonResponse
    {
        $savedFilter = $service->owned($request->user(), $scope)->findOrFail($id);
        $data = $service->validate($request, $scope, $savedFilter);
        $savedFilter->update(['name' => $data['name'], 'visibility' => $data['visibility']]);

        return response()->json($service->payload($savedFilter, $request->user()));
    }
}
