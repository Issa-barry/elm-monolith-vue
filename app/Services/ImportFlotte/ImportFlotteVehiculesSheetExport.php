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
            'vehicule_categorie',
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
        // vehicule_capacite_bouteilles sont facultatives : laissées vides, le véhicule reste
        // non plafonné pour cette catégorie — aucune capacité n'est portée par le type de
        // véhicule (cf. ImportFlotteParser). La commission d'équipe se configure après coup
        // dans Équipes de livraison.
        //
        // vehicule_site est obligatoire pour tout véhicule, quel que soit son propriétaire.
        // vehicule_categorie : interne ou partenaire, obligatoire sur chaque ligne (même une
        // ligne d'ancrage pour un véhicule déjà existant) — jamais devinée silencieusement, cf.
        // ImportFlotteParser. "partenaire" exige les colonnes proprietaire_* renseignées ;
        // "interne" exige qu'elles restent vides.
        // vehicule_livraison_vente / vehicule_livraison_logistique : oui/non (yes/no, 1/0,
        // true/false acceptés) — une cellule vide vaut "non" (aucun usage par défaut,
        // jamais un usage vente implicite), cf. ImportFlotteParser::toUsageBool(). Un
        // véhicule sans aucun des deux reste importé mais non exploitable tant qu'un usage
        // n'est pas défini (cf. Vehicule::aAuMoinsUnUsage()).
        return [
            ['Matoto', 'Camion 1', 'RC-1234-A', 'Tricycle', 'interne', '80', '', 'oui', 'non', '', '', '', ''],
            ['Matoto', 'Camion 2', 'RC-5678-B', 'Tricycle', 'partenaire', '80', '', 'oui', 'non', 'Diallo', 'Mamadou', '622000001', 'GN'],
        ];
    }
}
