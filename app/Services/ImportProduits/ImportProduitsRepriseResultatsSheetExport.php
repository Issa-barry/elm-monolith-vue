<?php

namespace App\Services\ImportProduits;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Feuille "RESULTATS" du fichier de reprise — trace, pour chaque ligne source, ce qui a été
 * réellement fait (création/mise à jour/inchangé) et le SKU final, conformément au brief : "la
 * correspondance ligne source → produit créé/modifié → SKU final".
 */
class ImportProduitsRepriseResultatsSheetExport implements FromArray, WithHeadings, WithTitle
{
    /** @param  Collection<int, array>  $lignes  lignes du rapport final (post-exécution) */
    public function __construct(private readonly Collection $lignes) {}

    public function title(): string
    {
        return 'RESULTATS';
    }

    public function headings(): array
    {
        return ['numero_ligne', 'nom', 'sku_final', 'resultat', 'message'];
    }

    public function array(): array
    {
        return $this->lignes->map(function (array $l) {
            $message = match ($l['statut']) {
                'erreur' => implode(' | ', $l['erreurs']),
                'inchange' => 'Aucun changement.',
                default => empty($l['changements'])
                    ? 'Créé.'
                    : implode(' | ', array_map(
                        fn (string $champ, array $diff) => sprintf('%s : %s → %s', $champ, $diff['avant'] ?? 'Aucun', $diff['apres'] ?? 'Aucun'),
                        array_keys($l['changements']),
                        array_values($l['changements']),
                    )),
            };

            return [
                $l['numero_ligne'],
                ImportProduitsCellSanitizer::neutraliser($l['nom']),
                $l['sku'],
                $l['statut'],
                ImportProduitsCellSanitizer::neutraliser($message),
            ];
        })->values()->all();
    }
}
