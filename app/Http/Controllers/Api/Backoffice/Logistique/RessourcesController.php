<?php

namespace App\Http\Controllers\Api\Backoffice\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RessourcesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $userSite = $user->sites()->wherePivot('is_default', true)->first()
            ?? $user->sites()->first();

        $sites = Site::query()
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->orderBy('nom')
            ->get();

        $vehicules = Vehicule::query()
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->where('is_active', true)
            ->orderBy('nom_vehicule')
            ->get(['id', 'nom_vehicule', 'immatriculation']);

        return response()->json([
            'user_site' => $userSite ? [
                'id'   => $userSite->id,
                'nom'  => $userSite->nom,
                'code' => $userSite->code ?? '',
                'type' => $userSite->type?->value ?? '',
            ] : null,
            'sites'     => $sites->map(fn ($s) => [
                'id'   => $s->id,
                'nom'  => $s->nom,
                'code' => $s->code ?? '',
                'type' => $s->type?->value ?? '',
            ])->values(),
            'vehicules' => $vehicules,
        ]);
    }
}
