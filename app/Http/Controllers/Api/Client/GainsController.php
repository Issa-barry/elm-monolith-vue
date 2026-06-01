<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\CommissionPart;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GainsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $proprietaire = Proprietaire::query()
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->telephone, fn ($q2) => $q2->orWhere('telephone', $user->telephone)))
            ->first();

        $livreur = Livreur::query()
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->telephone, fn ($q2) => $q2->orWhere('telephone', $user->telephone)))
            ->first();

        if ($proprietaire === null && $livreur === null) {
            return response()->json($this->emptyResponse());
        }

        $parVehicule = CommissionPart::query()
            ->join('commissions_ventes AS cv', 'cv.id', '=', 'commission_parts.commission_vente_id')
            ->join('vehicules', 'vehicules.id', '=', 'cv.vehicule_id')
            ->when($user->organization_id, fn ($q) => $q->where('cv.organization_id', $user->organization_id))
            ->where(function ($q) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $q->orWhere(fn ($sq) => $sq
                        ->where('commission_parts.type_beneficiaire', 'proprietaire')
                        ->where('commission_parts.proprietaire_id', $proprietaire->id)
                    );
                }
                if ($livreur !== null) {
                    $q->orWhere(fn ($sq) => $sq
                        ->where('commission_parts.type_beneficiaire', 'livreur')
                        ->where('commission_parts.livreur_id', $livreur->id)
                    );
                }
            })
            ->select([
                'vehicules.id AS vehicule_id',
                'vehicules.nom_vehicule AS nom',
                'vehicules.immatriculation',
            ])
            ->selectRaw('
                SUM(commission_parts.montant_brut)  AS total_brut,
                SUM(commission_parts.montant_net)   AS total_net,
                SUM(commission_parts.montant_verse) AS total_verse,
                COUNT(DISTINCT cv.id)               AS nb_commandes
            ')
            ->groupBy('vehicules.id', 'vehicules.nom_vehicule', 'vehicules.immatriculation')
            ->orderBy('vehicules.nom_vehicule')
            ->get()
            ->map(fn ($row) => [
                'vehicule_id'    => $row->vehicule_id,
                'nom'            => $row->nom,
                'immatriculation'=> $row->immatriculation,
                'total_brut'     => (float) $row->total_brut,
                'total_net'      => (float) $row->total_net,
                'total_verse'    => (float) $row->total_verse,
                'total_restant'  => max(0.0, (float) $row->total_net - (float) $row->total_verse),
                'nb_commandes'   => (int) $row->nb_commandes,
            ])
            ->values();

        return response()->json([
            'total_brut'    => (float) $parVehicule->sum('total_brut'),
            'total_net'     => (float) $parVehicule->sum('total_net'),
            'total_verse'   => (float) $parVehicule->sum('total_verse'),
            'total_restant' => (float) $parVehicule->sum('total_restant'),
            'nb_commandes'  => (int) $parVehicule->sum('nb_commandes'),
            'par_vehicule'  => $parVehicule,
        ]);
    }

    private function emptyResponse(): array
    {
        return [
            'total_brut'    => 0.0,
            'total_net'     => 0.0,
            'total_verse'   => 0.0,
            'total_restant' => 0.0,
            'nb_commandes'  => 0,
            'par_vehicule'  => [],
        ];
    }
}
