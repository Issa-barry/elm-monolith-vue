<?php

namespace App\Http\Controllers\Api\Backoffice\Logistique;

use App\Enums\StatutTransfert;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Logistique\TransfertResource;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Notifications\TransfertReceptionneeNotification;
use App\Services\Notification\BeneficiaireUserResolver;
use App\Services\Notification\NotificationDispatcher;
use App\Services\TransfertActiviteService;
use App\Services\TransfertLogistiqueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ValiderReceptionController extends Controller
{
    public function __invoke(Request $request, TransfertLogistique $transfert): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->organization_id && $transfert->organization_id !== $user->organization_id) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        if ($transfert->statut !== StatutTransfert::TRANSIT) {
            return response()->json(['message' => 'Seul un transfert en transit peut être réceptionné.'], 422);
        }

        try {
            $transfert = TransfertLogistiqueService::avancerStatut($transfert);
        } catch (ValidationException $e) {
            return response()->json(['message' => implode(' ', $e->errors()['statut'] ?? [])], 422);
        }

        TransfertActiviteService::log($transfert, 'reception_validee', [], $user->id);

        $this->notifierReception($transfert);

        $transfert->load([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes.variante:id,produit_id,sku',
            // image_url est un accesseur (dérivé de produit_medias, pas une colonne) : on charge
            // la relation medias plutôt que de la lister dans un select() limité aux colonnes.
            'lignes.variante.produit:id,nom',
            'lignes.variante.produit.medias',
            'commission.parts',
        ]);

        return response()->json(new TransfertResource($transfert));
    }

    /**
     * "Livraison terminée" pour le propriétaire du véhicule uniquement — jamais
     * le livreur, qui vient lui-même d'effectuer l'action (cf. rapport
     * notifications phase 1, 2026-08-27). Jamais de rethrow : un échec d'envoi
     * ne doit jamais faire annuler une réception déjà validée.
     */
    private function notifierReception(TransfertLogistique $transfert): void
    {
        try {
            $vehicule = $transfert->vehicule()->with('proprietaire')->first();
            $proprietaire = $vehicule?->proprietaire;
            if (! $proprietaire) {
                return;
            }

            $destinataire = BeneficiaireUserResolver::resolve('proprietaire', $proprietaire->id);

            NotificationDispatcher::send(
                new TransfertReceptionneeNotification($transfert->id, $transfert->reference),
                [$destinataire],
                'livraisons',
            );
        } catch (Throwable $e) {
            Log::error('TransfertReceptionneeNotification : envoi échoué', [
                'transfert_id' => $transfert->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
