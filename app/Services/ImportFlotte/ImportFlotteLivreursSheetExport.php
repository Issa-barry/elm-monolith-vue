<?php

namespace App\Services\ImportFlotte;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ImportFlotteLivreursSheetExport implements FromArray, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'livreurs';
    }

    public function headings(): array
    {
        return [
            'vehicule_immatriculation',
            'livreur_nom_complet',
            'livreur_telephone',
            'livreur_role',
        ];
    }

    public function array(): array
    {
        // Une ligne par livreur — vehicule_immatriculation fait le lien avec la
        // feuille "vehicules" (répéter l'immatriculation pour chaque livreur
        // d'un même véhicule, sans dupliquer le reste). Le montant par pack de
        // chaque livreur se configure après coup dans Équipes de livraison.
        // livreur_nom_complet est facultatif : un livreur identifié uniquement
        // par un surnom ou une désignation opérationnelle (ex: "Chauffeur 1")
        // est accepté, seul le téléphone est obligatoire.
        return [
            ['RC-1234-A', 'Ibrahima Camara', '623000001', 'chauffeur'],
            ['RC-1234-A', '', '623000002', 'convoyeur'],
        ];
    }
}
