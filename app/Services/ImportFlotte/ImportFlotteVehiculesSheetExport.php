<?php

namespace App\Services\ImportFlotte;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ImportFlotteVehiculesSheetExport implements FromArray, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'vehicules';
    }

    public function headings(): array
    {
        return [
            'vehicule_site',
            'vehicule_nom',
            'vehicule_immatriculation',
            'vehicule_type',
            'vehicule_capacite_sachets',
            'vehicule_capacite_bouteilles',
            'vehicule_livraison_vente',
            'vehicule_livraison_logistique',
            'proprietaire_nom',
            'proprietaire_prenom',
            'proprietaire_telephone',
            'proprietaire_pays',
        ];
    }

    public function array(): array
    {
        // Une seule ligne par véhicule. vehicule_capacite_sachets et
        // vehicule_capacite_bouteilles sont facultatives : laissées vides, la
        // capacité par défaut du type de véhicule s'applique (cf.
        // ImportFlotteParser). La commission d'équipe se configure après coup
        // dans Équipes de livraison.
        //
        // vehicule_site est obligatoire pour tout véhicule, quel que soit son propriétaire.
        // Un véhicule doit être autorisé pour la vente et/ou la logistique (au moins un "oui").
        return [
            ['Matoto', 'Camion 1', 'RC-1234-A', 'Tricycle', '80', '', 'oui', 'non', 'Diallo', 'Mamadou', '622000001', 'GN'],
        ];
    }
}
