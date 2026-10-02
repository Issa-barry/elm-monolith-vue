<?php

namespace App\Http\Controllers\SavedFilters;

use App\Http\Controllers\Controller;
use App\Services\SavedFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SetDefaultSavedFilterController extends Controller
{
    public function __invoke(Request $request, SavedFilterService $service, string $scope): JsonResponse
    {
        $data = $request->validate(['id' => ['present', 'nullable', 'ulid']]);
        $service->setDefault($request->user(), $scope, $data['id']);

        return response()->json(['default_id' => $data['id']]);
    }
}
