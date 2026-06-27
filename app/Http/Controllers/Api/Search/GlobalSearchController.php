<?php

namespace App\Http\Controllers\Api\Search;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Search\GlobalSearchRequest;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;

class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, GlobalSearchService $service): JsonResponse
    {
        $startedAt = microtime(true);

        $results = $service->search(
            query: $request->string('q')->trim()->toString(),
            user: $request->user(),
            limit: (int) $request->input('limit', 5),
            onlyCategories: $request->input('categories'),
        );

        return response()->json([
            'query' => $request->string('q')->trim()->toString(),
            'took_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'results' => $results,
        ]);
    }
}
