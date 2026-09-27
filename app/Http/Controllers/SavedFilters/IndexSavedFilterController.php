<?php

namespace App\Http\Controllers\SavedFilters;

use App\Http\Controllers\Controller;
use App\Services\SavedFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IndexSavedFilterController extends Controller
{
    public function __invoke(Request $request, SavedFilterService $service, string $scope): JsonResponse
    {
        $views = $service->visible($request->user(), $scope)->with('user')->orderBy('name')->get();

        return response()->json([
            'views' => $views->map(fn ($view) => $service->payload($view, $request->user())),
            'default_id' => $service->defaultId($request->user(), $scope),
            'can_share' => $service->canShare($request->user(), $scope),
        ]);
    }
}
