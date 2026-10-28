<?php

namespace App\Services\ImportRh;

use App\Services\ImportProduits\ImportProduitsCellSanitizer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Feuille « references » (jamais importée) : valeurs acceptées pour service, fonction, contrat. */
class ImportRhReferencesSheetExport implements FromArray, WithTitle
{
    public function __construct(
        private readonly Collection $services,
        private readonly Collection $fonctions,
    ) {}

    public function title(): string
    {
        return 'references';
    }

    public function array(): array
    {
        $texte = fn (?string $v) => ImportProduitsCellSanitizer::neutraliser($v) ?? '';
        $lignes = [['Services RH actifs (libellé ou code)'], ['libelle', 'code']];
        foreach ($this->services as $s) {
            $lignes[] = [$texte($s->libelle), $texte($s->code)];
        }

        $lignes[] = [];
        $lignes[] = ['Fonctions RH actives (libellé ou code)'];
        $lignes[] = ['libelle', 'code'];
        foreach ($this->fonctions as $f) {
            $lignes[] = [$texte($f->libelle), $texte($f->code)];
        }

        $lignes[] = [];
        $lignes[] = ['Types de contrat'];
        $lignes[] = ['CDI', 'Contrat à durée indéterminée — pas de date de fin'];
        $lignes[] = ['CDD', 'Contrat à durée déterminée — date de fin obligatoire'];

        return $lignes;
    }
}
