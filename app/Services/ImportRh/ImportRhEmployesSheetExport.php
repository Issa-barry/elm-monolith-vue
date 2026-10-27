<?php

namespace App\Services\ImportRh;

use App\Models\Employe;
use App\Services\ImportProduits\ImportProduitsCellSanitizer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Feuille « employes » pré-remplie avec les valeurs actuelles : seules les cellules modifiées
 * changeront quelque chose à l'import. Textes neutralisés contre l'injection de formule.
 *
 * Le matricule (colonne A, 6 chiffres) est écrit en TEXTE : en nombre, Excel perdrait ses zéros de
 * tête (« 004213 » → 4213).
 */
class ImportRhEmployesSheetExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithHeadings, WithTitle
{
    public function __construct(
        private readonly Collection $employes,
        private readonly bool $avecSalaire,
    ) {}

    public function title(): string
    {
        return ColonnesImportRh::FEUILLE;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($cell->getColumn() === 'A' && $value !== null && $value !== '') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function headings(): array
    {
        return ColonnesImportRh::entetes($this->avecSalaire);
    }

    public function array(): array
    {
        return $this->employes
            ->map(function (Employe $e) {
                $contrat = $e->contratActif;
                $texte = fn (?string $v) => ImportProduitsCellSanitizer::neutraliser($v) ?? '';

                return [
                    $e->matricule,
                    $texte($e->nom),
                    $texte($e->prenom),
                    $texte($e->site?->nom),
                    $e->date_embauche?->format('d/m/Y') ?? '',
                    $texte($e->service?->libelle),
                    $texte($e->fonction?->libelle),
                    $contrat ? strtoupper($contrat->type_contrat->value) : '',
                    $contrat?->date_debut?->format('d/m/Y') ?? '',
                    $contrat?->date_fin?->format('d/m/Y') ?? '',
                    ...($this->avecSalaire ? [$contrat?->salaire_base !== null ? (float) $contrat->salaire_base : ''] : []),
                ];
            })
            ->values()
            ->all();
    }
}
