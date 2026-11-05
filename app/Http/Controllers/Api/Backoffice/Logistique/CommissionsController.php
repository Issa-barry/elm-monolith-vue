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
            ->where('organization_id', $user->organization_id)
            ->when(! $user->voitToutesLesAgences(), fn (Builder $q) => $q->whereHas('transfert', fn (Builder $t) => $t->dansAgencesDe($user)))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(CommissionResource::collection($commissions));
    }

    public function show(CommissionLogistique $commission): JsonResponse
    {
        $commission->load(['parts', 'transfert:id,reference,statut']);

        return response()->json(new CommissionResource($commission));
    }
}
