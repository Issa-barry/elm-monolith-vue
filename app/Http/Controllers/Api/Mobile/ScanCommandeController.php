<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\TransfertLogistique;
use Illuminate\Http\JsonResponse;

class ScanCommandeController extends Controller
{
    public function __invoke(string $reference): JsonResponse
    {
        $ref = strtoupper(trim($reference));

        if (str_starts_with($ref, 'CMD-')) {
            return $this->scanCommande($ref);
        }

        if (str_starts_with($ref, 'TR-')) {
            return $this->scanTransfert($ref);
        }

        return response()->json(['message' => 'Référence non reconnue.'], 404);
    }

    private function scanCommande(string $reference): JsonResponse
    {
        $commande = CommandeVente::with([
            'site:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'vehicule.equipe:id,vehicule_id',
            'client:id,nom,prenom,telephone,adresse,quartier,ville',
            'lignes:id,commande_vente_id,quantite_demandee',
        ])->where('reference', $reference)->first();

        if (! $commande) {
            return response()->json(['message' => 'Commande introuvable.'], 404);
        }

        $client = $commande->client;
        $clientNom = $client
            ? trim(($client->prenom ?? '').' '.($client->nom ?? ''))
            : 'Vente directe';

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

    private function scanTransfert(string $reference): JsonResponse
    {
        $transfert = TransfertLogistique::with([
            'siteSource:id,nom',
            'siteDestination:id,nom',
            'vehicule:id,nom_vehicule,immatriculation',
            'equipeLivraison:id,vehicule_id', 'equipeLivraison.vehicule:id,nom_vehicule',
            'lignes',
        ])->where('reference', $reference)->first();

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
