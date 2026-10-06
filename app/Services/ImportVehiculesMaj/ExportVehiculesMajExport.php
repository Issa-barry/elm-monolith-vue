<?php

namespace App\Services\ImportVehiculesMaj;

use App\Models\Categorie;
use App\Models\Vehicule;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export "Exporter pour mise à jour" (VehiculeController::exportMaj()) — une ligne par
 * véhicule déjà en base, préremplie avec son état réel actuel, pour être réimportée telle
 * quelle via ImportVehiculesMajController après modification des seules colonnes autorisées.
 *
 * Colonnes et ordre STRICTEMENT identiques à ce qu'attend ImportVehiculesMajParser (même
 * convention "capacite__<REFERENCE>" dynamique qu'ImportFlotteVehiculesSheetExport) — export et
 * gabarit d'import doivent rester exactement compatibles (cf. brief) : l'utilisateur télécharge
 * cet export, modifie les valeurs autorisées et le réimporte immédiatement sans rien
 * réorganiser. `vehicule_immatriculation` est en première colonne mais n'est jamais modifiable
 * (clé de recherche, cf. ImportVehiculesMajParser) — les données sensibles/inchangeables du
 * véhicule (nom, marque, modèle, type, catégorie, propriétaire...) n'apparaissent jamais dans
 * ce fichier de maintenance.
 */
class ExportVehiculesMajExport implements FromArray, WithHeadings, WithTitle
{
    /** @param  Collection<int, Vehicule>  $vehicules  chargés avec ['site', 'capacites'] */
    public function __construct(
        private readonly Collection $vehicules,
        private readonly Collection $categories = new Collection,
    ) {}

    public function title(): string
    {
        return 'vehicules';
    }

    public function headings(): array
    {
        return [
            'vehicule_immatriculation',
            'vehicule_site',
            ...$this->colonnesCapacite(),
            'vehicule_livraison_vente',
            'vehicule_livraison_logistique',
        ];
    }

    public function array(): array
    {
        return $this->vehicules
            ->map(function (Vehicule $v) {
                $capacitesParCategorie = $v->capacites->keyBy('categorie_id');

                $colonnesCapacite = $this->categories
                    ->map(fn (Categorie $c) => $capacitesParCategorie->get($c->id)?->capacite_max ?? '')
                    ->all();

                return [
                    $v->immatriculation,
                    $v->site?->nom ?? '',
                    ...$colonnesCapacite,
                    $v->livraison_vente ? 'oui' : 'non',
                    $v->livraison_logistique ? 'oui' : 'non',
                ];
            })
            ->values()
            ->all();
    }

    /** @return string[] */
    private function colonnesCapacite(): array
    {
        return $this->categories
            ->map(fn (Categorie $c) => 'capacite__'.$c->reference)
            ->all();
    }
}
