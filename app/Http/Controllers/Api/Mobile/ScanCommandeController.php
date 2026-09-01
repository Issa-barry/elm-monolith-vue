<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\TransfertLogistique;
use Illuminate\Http\JsonResponse;

class ScanCommandeController extends Controller
{
    /**
     * VTE-/DST- = nouvelles commandes (cf. NatureOperation::prefixeReference()) ; CMD- =
     * référence émise avant le chantier du 31/08/2026, jamais renommée, toujours reconnue.
     */
    private const COMMANDE_PREFIXES = ['VTE-', 'DST-', 'CMD-'];

    /** TRF- = nouveaux transferts ; TR- = référence émise avant le 31/08/2026. */
    private const TRANSFERT_PREFIXES = ['TRF-', 'TR-'];

    public function __invoke(string $reference): JsonResponse
    {
        $ref = strtoupper(trim($reference));
        // Depuis le chantier "références par processus" (31/08/2026), la séquence de
        // ReferenceNumeroService est scopée par organisation : deux organisations peuvent
        // légitimement porter EXACTEMENT la même référence (ex: VTE-310826-001 pour l'une ET
        // l'autre). Une recherche par reference seule n'est donc plus seulement une faille
        // multi-tenant — elle devient fonctionnellement ambiguë et peut retourner la commande
        // de la mauvaise organisation. Toujours filtrer par organization_id de l'utilisateur
        // authentifié, sur les deux générations de préfixes (legacy comprise).
        $organizationId = auth()->user()->organization_id;

        if ($this->startsWithAny($ref, self::COMMANDE_PREFIXES)) {
            return $this->scanCommande($ref, $organizationId);
        }

        if ($this->startsWithAny($ref, self::TRANSFERT_PREFIXES)) {
            return $this->scanTransfert($ref, $organizationId);
        }

        return response()->json(['message' => 'Référence non reconnue.'], 404);
    }

    /** @param  list<string>  $prefixes */
    private function startsWithAny(string $ref, array $prefixes): bool
    {
        foreach ($prefixes as $prefixe) {
            if (str_starts_with($ref, $prefixe)) {
                return true;
            }
        }

        return false;
    }

    private function scanCommande(string $reference, string $organizationId): JsonResponse
    {
        $commande = CommandeVente::with([
            'site:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'vehicule.equipe:id,vehicule_id',
            'client:id,nom,prenom,telephone,adresse,quartier,ville',
            'lignes:id,commande_vente_id,quantite_demandee',
        ])->where('reference', $reference)->where('organization_id', $organizationId)->first();

        if (! $commande) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        $client = $commande->client;
        $clientNom = $client?->nom_complet ?? 'Vente directe';

        return response()->json([
            'type' => 'commande',
            'reference' => $commande->reference ?? '—',
            'statut' => $commande->statut?->value ?? '—',
            'statut_label' => $commande->statut?->label() ?? '—',
            'site_source' => $commande->site?->nom ?? '—',
            'client_nom' => $clientNom,
            'client_telephone' => $client?->telephone,
            'client_adresse' => implode(', ', array_filter([
                $client?->adresse,
                $client?->quartier,
                $client?->ville,
            ])) ?: null,
            'vehicule' => $commande->vehicule ? [
                'nom' => $commande->vehicule->nom_vehicule,
                'immatriculation' => $commande->vehicule->immatriculation,
            ] : null,
            'equipe_nom' => $commande->vehicule?->nom_vehicule ?? '—',
            'date_commande' => $commande->validated_at?->toDateString(),
            'nb_packs' => (int) $commande->lignes->sum('quantite_demandee'),
            'total' => (float) $commande->total_commande,
        ]);
    }

    private function scanTransfert(string $reference, string $organizationId): JsonResponse
    {
        $transfert = TransfertLogistique::with([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes',
        ])->where('reference', $reference)->where('organization_id', $organizationId)->first();

        if (! $transfert) {
            return response()->json(['message' => 'Transfert introuvable.'], 404);
        }

        return response()->json([
            'type' => 'transfert',
            'reference' => $transfert->reference,
            'statut' => $transfert->statut instanceof \BackedEnum ? $transfert->statut->value : $transfert->statut,
            'statut_label' => 'Livraison en cours',
            'site_source' => $transfert->siteSource?->nom ?? '—',
            'site_destination' => $transfert->siteDestination?->nom ?? '—',
            'vehicule' => $transfert->vehicule ? [
                'nom' => $transfert->vehicule->nom_vehicule,
                'immatriculation' => $transfert->vehicule->immatriculation,
            ] : null,
            'equipe_nom' => $transfert->equipeLivraison?->nom ?? '—',
            'date_depart' => $transfert->date_depart_reelle?->toDateString(),
            'date_arrivee_prevue' => $transfert->date_arrivee_prevue?->toDateString(),
            'nb_packs' => (int) $transfert->lignes->sum('quantite_chargee'),
        ]);
    }
}
