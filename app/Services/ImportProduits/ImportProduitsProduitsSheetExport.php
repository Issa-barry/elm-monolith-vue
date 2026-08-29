<?php

namespace App\Services\ImportProduits;

use App\Enums\ProduitStatut;
use App\Models\ProduitType;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Feuille "PRODUITS" du modèle d'import — seul onglet réellement lu par ImportProduitsParser
 * (cf. son docblock). Toujours vide de données (aucune ligne d'exemple ici, contrairement au
 * modèle Import Flotte/Sites) : les exemples vivent dans l'onglet EXEMPLES, qui n'est jamais
 * importé — l'utilisateur démarre sur une feuille strictement vierge.
 */
class ImportProduitsProduitsSheetExport implements FromArray, WithEvents, WithHeadings, WithTitle
{
    public const COLONNES = [
        'sku', 'nom', 'type_code', 'categorie_reference', 'fournisseur_reference', 'statut',
        'code_barres', 'prix_achat', 'prix_usine_autres_vehicules', 'prix_usine_tricycle', 'prix_vente',
        'prix_externe', 'prix_revendeur', 'prix_distributeur', 'cout',
        'alerte_stock_active', 'seuil_alerte_stock', 'description',
    ];

    /** @param  Collection<int, ProduitType>  $typesActifs */
    public function __construct(private readonly Collection $typesActifs = new Collection) {}

    public function title(): string
    {
        return 'PRODUITS';
    }

    public function headings(): array
    {
        return self::COLONNES;
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:R1');

                // Colonnes texte (jamais de conversion numérique silencieuse par Excel — même
                // piège que `code_facultatif` chez ImportSites) : sku, type_code,
                // categorie_reference, fournisseur_reference, code_barres.
                foreach (['A', 'C', 'D', 'E', 'G'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}501")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }
                // Colonnes prix : entiers, jamais de décimales (montants GNF). H..K = tarification
                // usine/vente, L..N = tarification par nature de client (obligatoires pour un
                // produit fabricable, cf. onglet REFERENCES), O = coût de revient.
                foreach (['H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}501")->getNumberFormat()->setFormatCode('0');
                }
                // Les deux libellés usine doivent rester lisibles sans agrandissement manuel.
                $sheet->getColumnDimension('I')->setWidth(32);
                $sheet->getColumnDimension('J')->setWidth(24);

                $codesTypes = implode(',', $this->typesActifs->pluck('code')->all());
                $statuts = implode(',', ProduitStatut::values());

                for ($row = 2; $row <= 501; $row++) {
                    if ($codesTypes !== '') {
                        $this->liste($sheet, "C{$row}", $codesTypes, 'Type invalide', 'Choisissez un code de type dans la liste (onglet REFERENCES).');
                    }
                    $this->liste($sheet, "F{$row}", $statuts, 'Statut invalide', 'Choisissez une valeur dans la liste.');
                    $this->liste($sheet, "P{$row}", 'oui,non', 'Valeur invalide', 'Choisissez oui ou non.');
                }
            },
        ];
    }

    private function liste(Worksheet $sheet, string $cellule, string $valeurs, string $titre, string $prompt): void
    {
        $validation = $sheet->getCell($cellule)->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setErrorTitle($titre);
        $validation->setError('Choisissez une valeur dans la liste.');
        $validation->setPromptTitle('Aide');
        $validation->setPrompt($prompt);
        $validation->setFormula1('"'.$valeurs.'"');
    }
}
