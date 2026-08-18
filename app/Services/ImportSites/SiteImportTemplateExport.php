<?php

namespace App\Services\ImportSites;

use App\Enums\SiteType;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Modèle Excel pour l'import en masse des sites — une feuille, une ligne par
 * site. `type` doit être l'une des valeurs de SiteType (seule source de
 * vérité, cf. SiteImportParser) : jamais recopiée en dur ailleurs, et
 * proposée en liste déroulante Excel sur la colonne pour éviter les fautes
 * de saisie.
 *
 * `code_facultatif` : identifiant métier de rapprochement pour un réimport
 * (cf. SiteImportParser). La colonne est forcée au format Texte pour éviter
 * qu'Excel ne transforme une saisie du type "001" en nombre "1", ce qui
 * ferait perdre les zéros initiaux avant même que le fichier soit uploadé.
 */
class SiteImportTemplateExport implements FromArray, WithEvents, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'sites';
    }

    public function headings(): array
    {
        return [
            'nom',
            'code_facultatif',
            'type',
            'ville_obligatoire',
            'quartier_obligatoire',
            'telephone_obligatoire',
            'description_facultatif',
            'site_parent_facultatif',
            'longitude_facultatif',
            'latitude_facultatif',
        ];
    }

    public function array(): array
    {
        // Exemple minimal montrant le format attendu, notamment le
        // rattachement d'un enfant à son parent par NOM (cf. SiteImportParser) —
        // un même fichier peut contenir le parent et l'enfant, dans n'importe
        // quel ordre. La colonne code_facultatif reste vide dans l'exemple :
        // un code y ressemblerait à un identifiant déjà attribué en base,
        // ce qui n'est pas le cas pour ces lignes d'exemple.
        return [
            ['Matoto', '', 'Siège', 'Conakry', 'Matoto', '+224664039160', '', '', '', ''],
            ['Cba', '', 'Usine', 'Conakry', 'Kountia', '+224626078393', '', 'Matoto', '', ''],
            ['Lambanyi', '', 'Dépôt', 'Conakry', 'Lambanyi', '+224622671016', '', 'Matoto', '', ''],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $labels = implode(',', array_map(fn (SiteType $t) => $t->label(), SiteType::cases()));

                // Colonne B (code_facultatif) : format Texte pour empêcher Excel
                // de transformer une saisie "001" en nombre "1" — cf. docblock
                // de la classe.
                $event->sheet->getDelegate()->getStyle('B2:B200')
                    ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

                // Colonne C (type), lignes 2 à 200 : marge confortable pour
                // les lignes ajoutées après le remplissage de l'exemple.
                for ($row = 2; $row <= 200; $row++) {
                    $validation = $event->sheet->getCell("C{$row}")->getDataValidation();
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setErrorStyle(DataValidation::STYLE_STOP);
                    $validation->setAllowBlank(true);
                    $validation->setShowInputMessage(true);
                    $validation->setShowErrorMessage(true);
                    $validation->setShowDropDown(true);
                    $validation->setErrorTitle('Type invalide');
                    $validation->setError('Choisissez une valeur dans la liste.');
                    $validation->setPromptTitle('Type de site');
                    $validation->setPrompt('Choisissez une valeur dans la liste.');
                    $validation->setFormula1('"'.$labels.'"');
                }
            },
        ];
    }
}
