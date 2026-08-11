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
            'vehicule_categorie',
            'vehicule_pris_en_charge_par_usine',
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
        // vehicule_site est obligatoire quelle que soit la catégorie : un
        // véhicule externe est aussi rattaché à un site (celui pour lequel il
        // opère), même s'il appartient à un propriétaire privé.
        return [
            ['Matoto', 'Camion 1', 'RC-1234-A', 'Tricycle', '80', '', 'externe', 'oui', 'Diallo', 'Mamadou', '622000001', 'GN'],
        ];
    }
}
