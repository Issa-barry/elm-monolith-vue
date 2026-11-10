<?php

namespace App\Http\Resources\Api\Client\Operations;

use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Services\Client\Operations\PresentationOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Détail d'une vente dans la liste « Commandes » (ADR 0058). Le contenu suit les liens du compte :
 * lignes avec prix et total pour le client, quantités pour les autres, paiement pour le client et le
 * propriétaire, coordonnées du client pour le livreur pendant la livraison. Jamais de commission.
 *
 * @mixin CommandeVente
 */
class OperationVenteResource extends JsonResource
{
    /** @param  list<string>  $liens */
    public function __construct(CommandeVente $resource, private readonly array $liens)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $date = $this->validated_at ?? $this->created_at;
        $paiement = PresentationOperation::paiementVente($this->resource, $this->liens);
        $voitLesPrix = PresentationOperation::voitLesPrix($this->liens);
        $voitLeContact = PresentationOperation::voitLeContact($this->resource, $this->liens);

        return [
            'id' => $this->id,
            'type' => 'vente',
            'reference' => $this->reference ?? '—',
            'nature' => PresentationOperation::natureVente($this->resource),
            'liens' => $this->liens,
            'avancement' => PresentationOperation::avancementVente($this->resource),
            'statut_paiement' => $paiement['statut'],
            'statut_paiement_label' => $paiement['label'],
            'remise' => PresentationOperation::remise($this->resource),
            'site_source' => $this->site?->nom ?? '—',
            'site_destination' => $this->client?->nom_complet ?? 'Vente directe',
            'vehicule' => $this->vehicule ? [
                'id' => $this->vehicule->id,
                'nom_vehicule' => $this->vehicule->nom_vehicule,
                'immatriculation' => $this->vehicule->immatriculation,
            ] : null,
            'date' => $date?->toDateString(),
            'nb_packs' => (int) $this->lignes->sum('quantite_demandee'),
            'lignes' => $this->lignes->map(fn (CommandeVenteLigne $ligne) => [
                'id' => $ligne->id,
                'libelle' => $ligne->libelle_snapshot,
                'quantite' => $ligne->quantite_effective,
                'prix_unitaire' => $voitLesPrix ? (float) $ligne->prix_vente_snapshot : null,
                'total' => $voitLesPrix ? (float) $ligne->total_ligne : null,
            ])->values()->all(),
            'total' => $voitLesPrix ? (float) $this->total_commande : null,
            'contact' => $voitLeContact ? [
                'telephone' => $this->client?->telephone,
                'adresse' => PresentationOperation::adresse($this->client),
            ] : null,
        ];
    }
}
