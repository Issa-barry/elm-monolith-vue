<?php

namespace App\Services\Vehicules;

use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export "Exporter les véhicules" (VehiculeController::export()) — un instantané en lecture
 * seule de la liste des véhicules, mêmes colonnes que Vehicules/Index.vue. À ne jamais confondre
 * avec ExportVehiculesMajExport (« Exporter pour mise à jour ») : celui-ci n'est pas réimportable
 * — pas de convention `capacite__<REFERENCE>`, colonnes de lecture (propriétaire, équipe, statut)
 * absentes du gabarit de mise à jour.
 */
class VehiculeListExport implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    /** @param  Collection<int, Vehicule>  $vehicules  chargés avec ['typeVehicule', 'site', 'proprietaire', 'equipe.membres.livreur', 'capacites.categorie'] */
    public function __construct(private readonly Collection $vehicules) {}

    public function title(): string
    {
        return 'vehicules';
    }

    public function collection(): Collection
    {
        return $this->vehicules;
    }

    public function headings(): array
    {
        return [
            'Véhicule',
            'Immatriculation',
            'Type',
            'Capacités',
            'Site',
            'Catégorie propriétaire',
            'Propriétaire',
            'Téléphone propriétaire',
            'Équipe (chauffeur)',
            'Usage vente',
            'Usage logistique',
            'Statut',
        ];
    }

    public function map($vehicule): array
    {
        /** @var Vehicule $vehicule */
        $premierChauffeur = $vehicule->equipe?->membres
            ->sortBy('ordre')
            ->firstWhere('role', 'chauffeur');

        return [
            $vehicule->nom_vehicule,
            $vehicule->immatriculation,
            $vehicule->type_label,
            $this->capacitesLabel($vehicule->capacites),
            $vehicule->site?->nom ?? '',
            $vehicule->categorie_label,
            $vehicule->proprietaire?->nom_affichage ?? '',
            $vehicule->proprietaire?->telephone ?? '',
            $premierChauffeur?->livreur?->nom_complet ?? '',
            $vehicule->livraison_vente ? 'Oui' : 'Non',
            $vehicule->livraison_logistique ? 'Oui' : 'Non',
            $vehicule->is_active ? 'Actif' : 'Inactif',
        ];
    }

    /** @param  Collection<int, VehiculeCapacite>  $capacites */
    private function capacitesLabel(Collection $capacites): string
    {
        return $capacites
            ->map(fn (VehiculeCapacite $c) => "{$c->categorie->nom} {$c->capacite_max}")
            ->implode(' · ');
    }
}
