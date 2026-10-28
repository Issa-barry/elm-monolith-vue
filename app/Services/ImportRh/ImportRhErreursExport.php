<?php

namespace App\Services\ImportRh;

use App\Services\ImportProduits\ImportProduitsCellSanitizer;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Fichier d'erreurs : lignes en erreur avec leur motif, mêmes colonnes lues que l'import (à
 * corriger puis redéposer). Valeurs déjà masquées par ImportRhPresenter (salaire seulement avec la
 * permission) ; textes neutralisés contre l'injection de formule ; matricule écrit en texte.
 */
class ImportRhErreursExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithHeadings, WithTitle
{
    /** @param  list<array>  $lignes  issues d'ImportRhPresenter::lignesEnErreur() */
    public function __construct(
        private readonly array $lignes,
        private readonly bool $avecSalaire,
    ) {}

    public function title(): string
    {
        return ColonnesImportRh::FEUILLE;
    }

    public function headings(): array
    {
        return [...$this->colonnes(), 'motif_erreur'];
    }

    public function array(): array
    {
        return array_map(function (array $ligne) {
            $cellules = array_map(
                fn (string $colonne) => ImportProduitsCellSanitizer::neutraliser($ligne['valeurs'][$colonne] ?? '') ?? '',
                $this->colonnes(),
            );

            return [...$cellules, ImportProduitsCellSanitizer::neutraliser(implode(' ', $ligne['erreurs'])) ?? ''];
        }, $this->lignes);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($cell->getColumn() === 'A' && $value !== null && $value !== '') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** @return list<string> */
    private function colonnes(): array
    {
        return [
            ColonnesImportRh::MATRICULE,
            ColonnesImportRh::DATE_EMBAUCHE,
            ColonnesImportRh::SERVICE,
            ColonnesImportRh::FONCTION,
            ...ColonnesImportRh::CONTRAT,
            ...($this->avecSalaire ? [ColonnesImportRh::SALAIRE] : []),
        ];
    }
}
