<?php

namespace App\Services\ImportRh;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Feuille « mode_emploi » (jamais importée) : vue d'ensemble des règles d'ImportRhParser. */
class ImportRhModeEmploiSheetExport implements FromArray, WithTitle
{
    public function __construct(private readonly bool $avecSalaire) {}

    public function title(): string
    {
        return 'mode_emploi';
    }

    public function array(): array
    {
        return [
            ['Import des dossiers et contrats RH — mode d\'emploi'],
            [],
            ['Seule la feuille « employes » est importée. Cet import ne crée jamais de salarié.'],
            ['Chaque ligne est retrouvée par son matricule (obligatoire). Les colonnes nom, prenom et agence ne sont jamais modifiées.'],
            [],
            ['Cellule vide = la valeur actuelle est conservée. Cellule renseignée = elle remplace la valeur actuelle.'],
            ['Dates au format JJ/MM/AAAA. Service et fonction : libellé ou code exact (feuille « references »).'],
            [],
            ['Contrat'],
            ['  Salarié sans contrat actif : les colonnes contrat remplies créent un contrat actif.'],
            ['  Salarié avec un contrat actif : les colonnes doivent correspondre à ce contrat, sinon la ligne est refusée.'],
            ['  Un contrat existant ne se modifie jamais par cet import (écran Contrats).'],
            ['  CDD : date de fin obligatoire et postérieure au début. CDI : pas de date de fin.'],
            ...($this->avecSalaire ? [
                [],
                ['Salaire de base (GNF)'],
                ['  Pris en compte seulement à la création du contrat.'],
                ['  Sur un contrat actif existant, il doit être identique au salaire du contrat : un contrat existant (salaire compris) se modifie dans l\'écran Contrats.'],
            ] : []),
            [],
            ['Après le dépôt, un aperçu affiche chaque changement (avant → après).'],
            ['Rien n\'est enregistré tant que l\'aperçu n\'est pas confirmé, et la confirmation est impossible tant qu\'une ligne est en erreur.'],
        ];
    }
}
