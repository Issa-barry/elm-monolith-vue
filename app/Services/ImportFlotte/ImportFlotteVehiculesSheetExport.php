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
            'vehicule_immatriculation',
            'vehicule_nom',
            'vehicule_type',
            'vehicule_categorie',
            'vehicule_site',
            'vehicule_pris_en_charge_par_usine',
            'proprietaire_nom',
            'proprietaire_prenom',
            'proprietaire_telephone',
            'proprietaire_pays',
        ];
    }

    public function array(): array
    {
        // Une seule ligne par véhicule — la capacité est résolue automatiquement
        // depuis le type de véhicule, pas besoin de la saisir ici. La commission
        // d'équipe se configure après coup dans Équipes de livraison.
        //
        // vehicule_site est obligatoire quelle que soit la catégorie : un
        // véhicule externe est aussi rattaché à un site (celui pour lequel il
        // opère), même s'il appartient à un propriétaire privé.
        return [
            ['RC-1234-A', 'Camion 1', 'Tricycle', 'externe', 'Matoto', 'oui', 'Diallo', 'Mamadou', '622000001', 'GN'],
        ];
    }
}
