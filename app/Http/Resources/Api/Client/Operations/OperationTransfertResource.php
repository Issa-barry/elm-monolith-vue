<?php

namespace App\Http\Resources\Api\Client\Operations;

use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Services\Client\Operations\PresentationOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Détail d'un transfert logistique dans la liste « Commandes » (ADR 0058) : aucun montant ni commission,
 * contrairement à la ressource du back-office.
 *
 * @mixin TransfertLogistique
 */
class OperationTransfertResource extends JsonResource
{
    /** @param  list<string>  $liens */
    public function __construct(TransfertLogistique $resource, private readonly array $liens)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $date = $this->date_depart_reelle ?? $this->created_at;

        return [
            'id' => $this->id,
            'type' => 'logistique',
            'reference' => $this->reference,
            'nature' => PresentationOperation::natureTransfert(),
            'liens' => $this->liens,
            'avancement' => PresentationOperation::avancementTransfert($this->resource),
            'statut_paiement' => null,
            'statut_paiement_label' => null,
            'remise' => null,
            'site_source' => $this->siteSource?->nom ?? '—',
            'site_destination' => $this->siteDestination?->nom ?? '—',
            'vehicule' => $this->vehicule ? [
                'id' => $this->vehicule->id,
                'nom_vehicule' => $this->vehicule->nom_vehicule,
                'immatriculation' => $this->vehicule->immatriculation,
            ] : null,
            'date' => $date?->toDateString(),
            'nb_packs' => (int) $this->lignes->sum('quantite_chargee'),
            'lignes' => $this->lignes->map(fn (TransfertLigne $ligne) => [
                'id' => $ligne->id,
                'libelle' => $ligne->variante?->libelle,
                'quantite' => (int) ($ligne->quantite_recue ?? $ligne->quantite_chargee ?? $ligne->quantite_demandee ?? 0),
                'prix_unitaire' => null,
                'total' => null,
            ])->values()->all(),
            'total' => null,
            'contact' => null,
        ];
    }
}
