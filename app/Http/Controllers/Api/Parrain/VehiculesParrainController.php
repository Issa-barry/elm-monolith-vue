<?php

namespace App\Http\Controllers\Api\Parrain;

use App\Http\Controllers\Api\Parrain\Concerns\ResoutParrainDuCompte;
use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Commission\PartParrainService;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehiculesParrainController extends Controller
{
    use ResoutParrainDuCompte;

    #[Endpoint(
        description: 'Véhicules que le parrain parraine actuellement, avec ses propres parts en vigueur '
            .'(GNF par pack). Jamais l\'équipe, le propriétaire ni la part des autres bénéficiaires. Un '
            .'véhicule retiré ou remplacé sort de cette liste, ses commissions restent dans `commissions`.',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $parrain = $this->parrainDuCompte($request);

        $vehicules = $parrain->vehicules()
            ->with(['site', 'equipe'])
            ->orderBy('nom_vehicule')
            ->get()
            ->map(fn (Vehicule $v) => [
                'id' => $v->id,
                'nom_vehicule' => $v->nom_vehicule,
                'immatriculation' => $v->immatriculation,
                'type_label' => $v->type_label,
                'site_nom' => $v->site?->nom,
                'is_active' => (bool) $v->is_active,
                'parts' => collect(PartParrainService::remuneration($v))->map(fn (array $part) => [
                    'processus_label' => $part['processus_label'],
                    'categorie_nom' => $part['categorie_nom'],
                    'montant' => $part['montant'],
                    'a_definir' => $part['a_repartir'],
                    'depuis' => $part['depuis'],
                ])->values()->all(),
            ])
            ->values();

        return response()->json(['data' => $vehicules]);
    }
}
