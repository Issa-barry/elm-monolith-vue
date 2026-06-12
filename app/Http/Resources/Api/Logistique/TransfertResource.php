<?php

namespace App\Http\Resources\Api\Logistique;

use App\Enums\StatutTransfert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransfertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lignesLoaded = $this->resource->relationLoaded('lignes');

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'statut' => $this->statut instanceof StatutTransfert ? $this->statut->value : $this->statut,
            'statut_label' => $this->statut_label,
            'statut_color' => $this->statut instanceof StatutTransfert ? $this->statut->color() : 'secondary',

            'site_source' => $this->whenLoaded('siteSource', fn () => [
                'id' => $this->siteSource->id,
                'nom' => $this->siteSource->nom,
            ], fn () => ['id' => $this->site_source_id, 'nom' => '—']),

            'site_destination' => $this->whenLoaded('siteDestination', fn () => [
                'id' => $this->siteDestination->id,
                'nom' => $this->siteDestination->nom,
            ], fn () => ['id' => $this->site_destination_id, 'nom' => '—']),

            'vehicule' => $this->whenLoaded('vehicule', function () {
                return $this->vehicule ? [
                    'id' => $this->vehicule->id,
                    'nom_vehicule' => $this->vehicule->nom_vehicule,
                    'immatriculation' => $this->vehicule->immatriculation,
                ] : null;
            }),

            'equipe' => $this->whenLoaded('equipeLivraison', function () {
                return $this->equipeLivraison ? [
                    'id' => $this->equipeLivraison->id,
                    'nom' => $this->equipeLivraison->nom,
                ] : null;
            }),

            'date_depart_prevue' => $this->date_depart_prevue?->toDateString(),
            'date_depart_reelle' => $this->date_depart_reelle?->toDateString(),
            'date_arrivee_prevue' => $this->date_arrivee_prevue?->toDateString(),
            'date_arrivee_reelle' => $this->date_arrivee_reelle?->toDateString(),

            'notes' => $this->notes,
            'code_confirmation' => $this->code_confirmation,

            'nb_packs_demandes' => $lignesLoaded ? (int) $this->lignes->sum('quantite_demandee') : null,
            'nb_packs_charges' => $lignesLoaded ? (int) $this->lignes->sum('quantite_chargee') : null,
            'nb_packs_recus' => $lignesLoaded ? (int) $this->lignes->sum('quantite_recue') : null,

            'lignes' => TransfertLigneResource::collection($this->whenLoaded('lignes')),

            'validation_reception' => $this->validation_reception,
            'validation_motif' => $this->validation_motif,

            'commission' => new CommissionResource($this->whenLoaded('commission')),

            'activites' => $this->whenLoaded('activites', fn () => $this->activites->map(fn ($a) => [
                'id' => $a->id,
                'action' => $a->action,
                'details' => $a->details,
                'user_id' => $a->user_id,
                'created_at' => $a->created_at?->toISOString(),
            ])),

            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
