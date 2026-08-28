<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppePart;
use App\Models\User;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\Data\GainsVehiculeRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint historique, déconseillé pour tout nouvel écran — préférer
 * `GET /v1/mobile/dashboard` (Api\Client\DashboardController), même moteur que
 * l'espace client Inertia. Celui-ci n'inclut PAS les commissions logistiques
 * (uniquement `CommissionEnveloppePart`, vente) et n'inclut pas les dépenses —
 * conservé tel quel pour ne pas casser un contrat mobile existant (cf.
 * docs/api-espace-client-contract.md §5).
 */
class GainsController extends Controller
{
    public function __invoke(Request $request, ClientIdentityResolver $identityResolver): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $identity = $identityResolver->resolve($user);
        $proprietaire = $identity->proprietaire;
        $livreur = $identity->livreur;

        // Ne filtre que par bénéficiaire proprietaire/livreur ci-dessous : un profil
        // "client" seul (sans proprietaire/livreur) n'a pas de gains à afficher ici.
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
            ->when($identity->organizationId, fn ($q) => $q->where('ce.organization_id', $identity->organizationId))
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
            ->map(fn ($row) => new GainsVehiculeRow(
                vehiculeId: $row->vehicule_id,
                nom: $row->nom,
                immatriculation: $row->immatriculation,
                totalBrut: (float) $row->total_brut,
                totalNet: (float) $row->total_net,
                totalAPayer: (float) $row->total_a_payer,
                totalVerse: (float) $row->total_verse,
                totalRestant: (float) max(0.0, (float) $row->total_a_payer - (float) $row->total_verse),
                nbCommandes: (int) $row->nb_commandes,
            ))
            ->values();

        return response()->json([
            'total_brut' => (float) $parVehicule->sum('totalBrut'),
            'total_net' => (float) $parVehicule->sum('totalNet'),
            'total_a_payer' => (float) $parVehicule->sum('totalAPayer'),
            'total_verse' => (float) $parVehicule->sum('totalVerse'),
            'total_restant' => (float) $parVehicule->sum('totalRestant'),
            'nb_commandes' => (int) $parVehicule->sum('nbCommandes'),
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
