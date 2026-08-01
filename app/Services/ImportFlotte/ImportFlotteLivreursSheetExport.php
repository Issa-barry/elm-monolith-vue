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
            'livreur_nom',
            'livreur_prenom',
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
        //
        // Le nom est entièrement facultatif — seul le téléphone est obligatoire
        // (cf. ImportFlotteParser::resoudreLivreurs) — et peut être renseigné de
        // trois façons, illustrées par les trois lignes d'exemple ci-dessous :
        //  1. livreur_nom_complet seul (surnom ou nom complet en un champ) ;
        //  2. livreur_nom + livreur_prenom séparés (repli automatique si
        //     livreur_nom_complet est vide) ;
        //  3. aucun des deux — le livreur reste identifié par son téléphone.
        return [
            ['RC-1234-A', 'Ibrahima Camara', '', '', '623000001', 'chauffeur'],
            ['RC-1234-A', '', 'Soumah', 'Fatoumata', '623000002', 'convoyeur'],
            ['RC-1234-A', '', '', '', '623000003', 'convoyeur'],
        ];
    }
}
