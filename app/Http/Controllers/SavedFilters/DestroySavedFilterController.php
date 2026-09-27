<?php

namespace App\Http\Controllers\SavedFilters;

use App\Http\Controllers\Controller;
use App\Services\SavedFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestroySavedFilterController extends Controller
{
    public function __invoke(Request $request, SavedFilterService $service, string $scope, string $id): JsonResponse
    {
        $service->owned($request->user(), $scope)->findOrFail($id)->delete();

        return response()->json(null, 200);
    }
}
