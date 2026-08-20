<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppePart;
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
                ->when($user->telephone, fn ($q2) => $q2->orWhereHas('personne', fn ($p) => $p->where('telephone', $user->telephone))))
            ->first();

        $livreur = Livreur::query()
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->telephone, fn ($q2) => $q2->orWhereHas('personne', fn ($p) => $p->where('telephone', $user->telephone))))
            ->first();

        if ($proprietaire === null && $livreur === null) {
            return response()->json($this->emptyResponse());
        }

        $parVehicule = CommissionEnveloppePart::query()
            ->join('commission_enveloppes AS ce', 'ce.id', '=', 'commission_enveloppe_parts.enveloppe_id')
            ->join('commandes_ventes AS cv', function ($join) {
                $join->on('cv.id', '=', 'ce.source_id')
                    ->where('ce.source_type', '=', CommandeVente::class);
            })
            ->join('vehicules', 'vehicules.id', '=', 'cv.vehicule_id')
            ->when($user->organization_id, fn ($q) => $q->where('ce.organization_id', $user->organization_id))
            ->where(function ($q) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $q->orWhere(fn ($sq) => $sq
                        ->where('commission_enveloppe_parts.beneficiaire_type', 'proprietaire')
                        ->where('commission_enveloppe_parts.beneficiaire_id', $proprietaire->id)
                    );
                }
                if ($livreur !== null) {
                    $q->orWhere(fn ($sq) => $sq
                        ->where('commission_enveloppe_parts.beneficiaire_type', 'livreur')
                        ->where('commission_enveloppe_parts.beneficiaire_id', $livreur->id)
                    );
                }
            })
            ->select([
                'vehicules.id AS vehicule_id',
                'vehicules.nom_vehicule AS nom',
                'vehicules.immatriculation',
            ])
            ->selectRaw('
                SUM(commission_enveloppe_parts.montant_brut)  AS total_brut,
                SUM(commission_enveloppe_parts.montant_net)   AS total_net,
                SUM(COALESCE(commission_enveloppe_parts.montant_actuel, commission_enveloppe_parts.montant_net)) AS total_a_payer,
                SUM(commission_enveloppe_parts.montant_verse) AS total_verse,
                COUNT(DISTINCT cv.id)               AS nb_commandes
            ')
            ->groupBy('vehicules.id', 'vehicules.nom_vehicule', 'vehicules.immatriculation')
            ->orderBy('vehicules.nom_vehicule')
            ->get()
            ->map(fn ($row) => [
                'vehicule_id' => $row->vehicule_id,
                'nom' => $row->nom,
                'immatriculation' => $row->immatriculation,
                'total_brut' => (float) $row->total_brut,
                'total_net' => (float) $row->total_net,
                'total_a_payer' => (float) $row->total_a_payer,
                'total_verse' => (float) $row->total_verse,
                'total_restant' => max(0.0, (float) $row->total_a_payer - (float) $row->total_verse),
                'nb_commandes' => (int) $row->nb_commandes,
            ])
            ->values();

        return response()->json([
            'total_brut' => (float) $parVehicule->sum('total_brut'),
            'total_net' => (float) $parVehicule->sum('total_net'),
            'total_a_payer' => (float) $parVehicule->sum('total_a_payer'),
            'total_verse' => (float) $parVehicule->sum('total_verse'),
            'total_restant' => (float) $parVehicule->sum('total_restant'),
            'nb_commandes' => (int) $parVehicule->sum('nb_commandes'),
            'par_vehicule' => $parVehicule,
        ]);
    }

    private function emptyResponse(): array
    {
        return [
            'total_brut' => 0.0,
            'total_net' => 0.0,
            'total_a_payer' => 0.0,
            'total_verse' => 0.0,
            'total_restant' => 0.0,
            'nb_commandes' => 0,
            'par_vehicule' => [],
        ];
    }
}
