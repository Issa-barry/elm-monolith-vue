<?php

namespace App\Http\Resources\Api\Client;

use App\Models\CommandeVente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche "mes commandes" espace client — dédiée, distincte des structures
 * backoffice (pas de champ interne type site_id brut, snapshot_id...). Les
 * lignes ne sont incluses que si la relation `lignes` a été explicitement
 * chargée par l'appelant (détail), jamais sur la liste (évite le N+1).
 *
 * @mixin CommandeVente
 */
class CommandeVenteMineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $date = $this->validated_at ?? $this->created_at;

        return [
            'id' => $this->id,
            'reference' => $this->reference ?? '—',
            'statut' => $this->statut?->value,
            'statut_label' => $this->statut_label,
            'date' => $date?->toDateString(),
            'total_commande' => (float) $this->total_commande,
            'vehicule' => $this->whenLoaded('vehicule', fn () => $this->vehicule ? [
                'id' => $this->vehicule->id,
                'nom_vehicule' => $this->vehicule->nom_vehicule,
                'immatriculation' => $this->vehicule->immatriculation,
            ] : null),
            'lignes' => $this->whenLoaded('lignes', fn () => $this->lignes->map(fn ($l) => [
                'id' => $l->id,
                'libelle' => $l->libelle_snapshot,
                'quantite_demandee' => $l->quantite_demandee,
                'quantite_livree' => $l->quantite_livree,
                'prix_vente_snapshot' => (float) $l->prix_vente_snapshot,
                'total_ligne' => (float) $l->total_ligne,
            ])),
        ];
    }
}
