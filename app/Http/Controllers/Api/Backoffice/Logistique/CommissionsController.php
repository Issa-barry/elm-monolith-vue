<?php

namespace App\Http\Controllers\Api\Backoffice\Logistique;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\CommissionResource;
use App\Models\CommissionLogistique;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $commissions = CommissionLogistique::query()
            ->with(['parts', 'transfert:id,reference'])
            ->when($user->organization_id, fn (Builder $q) => $q->where('organization_id', $user->organization_id))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(CommissionResource::collection($commissions));
    }

    public function show(Request $request, CommissionLogistique $commission): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id && $commission->organization_id !== $user->organization_id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        $commission->load(['parts', 'transfert:id,reference,statut']);

        return response()->json(new CommissionResource($commission));
    }
}
