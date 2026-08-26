<?php

namespace App\Services\ImportDepenseTypes;

use App\Enums\CategorieDepense;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;

/**
 * Modèle Excel pour l'import en masse des types de dépense — une feuille, une
 * ligne par type. `concerne` doit être l'une des valeurs de CategorieDepense
 * (seule source de vérité, cf. DepenseTypeImportParser) : jamais recopiée en
 * dur ailleurs, et proposée en liste déroulante Excel sur la colonne pour
 * éviter les fautes de saisie — même mécanisme que SiteImportTemplateExport.
 *
 * Les lignes d'exemple utilisent des libellés absents de DepenseTypesSeeder
 * pour rester importables telles quelles sur une organisation fraîchement
 * initialisée (pas de doublon avec les types par défaut).
 */
class DepenseTypeImportTemplateExport implements FromArray, WithEvents, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'types-depense';
    }

    public function headings(): array
    {
        return [
            'libelle',
            'concerne',
            'description_facultatif',
            'commentaire_obligatoire',
            'justificatif_obligatoire',
            'statut',
        ];
    }

    public function array(): array
    {
        return [
            ['Frais de mission', 'Interne', 'Déplacements professionnels hors agence.', 'Non', 'Oui', 'Actif'],
            ['Prime exceptionnelle', 'Salarié', '', 'Oui', 'Non', 'Actif'],
            ['Location matériel', 'Interne', '', 'Non', 'Non', 'Actif'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $labels = implode(',', array_map(fn (CategorieDepense $c) => $c->label(), CategorieDepense::cases()));
                $ouiNon = 'Oui,Non';
                $statuts = 'Actif,Inactif';

                for ($row = 2; $row <= 200; $row++) {
                    $this->listValidation($event, "B{$row}", $labels, 'Concerné invalide', 'Choisissez une valeur dans la liste.');
                    $this->listValidation($event, "D{$row}", $ouiNon, 'Valeur invalide', 'Choisissez Oui ou Non.');
                    $this->listValidation($event, "E{$row}", $ouiNon, 'Valeur invalide', 'Choisissez Oui ou Non.');
                    $this->listValidation($event, "F{$row}", $statuts, 'Statut invalide', 'Choisissez Actif ou Inactif.');
                }
            },
        ];
    }

    private function listValidation(AfterSheet $event, string $cell, string $values, string $errorTitle, string $prompt): void
    {
        $validation = $event->sheet->getCell($cell)->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setErrorTitle($errorTitle);
        $validation->setError($prompt);
        $validation->setPromptTitle($prompt);
        $validation->setPrompt($prompt);
        $validation->setFormula1('"'.$values.'"');
    }
}
