<?php

namespace App\Services\Rapports\Export;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Une feuille du classeur du rapport d'activité : en-têtes + lignes déjà mises en forme. */
class RapportFeuille implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  list<string>  $entetes
     * @param  list<list<mixed>>  $lignes
     */
    public function __construct(
        private readonly string $titre,
        private readonly array $entetes,
        private readonly array $lignes,
    ) {}

    public function title(): string
    {
        return $this->titre;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->entetes;
    }

    /** @return list<list<mixed>> */
    public function array(): array
    {
        return $this->lignes;
    }
}
