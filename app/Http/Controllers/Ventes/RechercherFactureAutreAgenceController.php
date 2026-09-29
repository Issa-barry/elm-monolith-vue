<?php

namespace App\Http\Controllers\Ventes;

use App\Http\Controllers\Controller;
use App\Models\FactureVente;
use App\Services\Tresorerie\AgenceEncaissementResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * « Encaisser une commande d'une autre agence » (ADR 0012) : retrouve UNE facture de l'organisation
 * par sa référence exacte — les listes Ventes/Factures restent limitées aux agences de
 * l'utilisateur — et renvoie ce qu'il faut pour l'encaisser dans l'une de SES agences : montant
 * restant, agences proposées avec leurs moyens de paiement et la disponibilité des espèces
 * (AgenceEncaissementResolver::pourEcran(), le même calcul que les autres écrans d'encaissement). Lecture seule : l'encaissement lui-même passe par
 * StoreEncaissementVenteController, qui rejoue tous les contrôles.
 *
 * Référence exacte seulement (jamais une recherche partielle) : on ne donne accès qu'à la facture
 * que le client présente, pas à la liste des ventes des autres agences.
 */
class RechercherFactureAutreAgenceController extends Controller
{
    public function __invoke(Request $request, AgenceEncaissementResolver $agences): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('factures.encaisser') && $user->can(AgenceEncaissementResolver::PERMISSION), 403, 'Action non autorisée.');

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:60'],
        ], [
            'reference.required' => 'Saisissez la référence de la commande ou de la facture.',
        ]);

        $reference = mb_strtoupper(trim($data['reference']));

        $facture = FactureVente::with(['commande.client', 'commande.site'])
            ->where('organization_id', $user->organization_id)
            ->where(fn ($q) => $q
                ->where('reference', $reference)
                ->orWhereHas('commande', fn ($c) => $c->where('reference', $reference)))
            ->first();

        if (! $facture) {
            return response()->json(['message' => 'Aucune facture ne correspond à cette référence.'], 404);
        }

        $encaissement = $agences->pourEcran($user, [$facture])[$facture->id];

        return response()->json([
            'facture' => [
                'id' => $facture->id,
                'reference' => $facture->reference,
                'commande_id' => $facture->commande_vente_id,
                'client_nom' => $facture->commande?->client?->nom_complet,
                'site_id' => $facture->site_id,
                'site_nom' => $facture->commande?->site?->nom,
                'montant_net' => (float) $facture->montant_net,
                'montant_encaisse' => (float) $facture->montant_encaisse,
                'montant_restant' => (float) $facture->montant_restant,
                'statut_facture' => $facture->statut_facture?->value,
                'statut_label' => $facture->statut_label,
            ],
            'raison_non_encaissable' => $this->raisonNonEncaissable($facture) ?? $encaissement['message'],
            'agences' => $encaissement['agences'],
            'agence_defaut' => $encaissement['agence_defaut'],
        ]);
    }

    /** Mêmes conditions que StoreEncaissementVenteController, exprimées pour l'écran. */
    private function raisonNonEncaissable(FactureVente $facture): ?string
    {
        return match (true) {
            $facture->isAnnulee() => 'Cette facture est annulée.',
            $facture->isPayee() || (float) $facture->montant_restant <= 0 => 'Cette facture est déjà entièrement payée.',
            $facture->commande && ! $facture->commande->isEncaissable() => 'Le chargement de cette commande doit être validé avant tout encaissement.',
            default => null,
        };
    }
}
