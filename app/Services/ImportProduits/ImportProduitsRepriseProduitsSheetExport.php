<?php

namespace App\Services\ImportProduits;

use App\Models\Produit;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Feuille "PRODUITS" du fichier de reprise — mêmes colonnes que le modèle d'import, mais
 * entièrement renseignées depuis l'état RÉEL en base (pas depuis le payload de la ligne
 * d'origine, qui peut être partiel) : SKU toujours présent (généré ou fourni), valeurs
 * normalisées. Réimporter ce fichier tel quel classe toutes les lignes "inchange" — c'est le
 * mécanisme qui ferme la boucle d'idempotence décrite par le brief.
 */
class ImportProduitsRepriseProduitsSheetExport implements FromArray, WithEvents, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, array>  $lignes  lignes du rapport final (post-exécution)
     * @param  Collection<string, Produit>  $produitsParId
     */
    public function __construct(
        private readonly Collection $lignes,
        private readonly Collection $produitsParId,
    ) {}

    public function title(): string
    {
        return 'PRODUITS';
    }

    public function headings(): array
    {
        return ImportProduitsProduitsSheetExport::COLONNES;
    }

    public function array(): array
    {
        return $this->lignes
            ->filter(fn (array $l) => $l['statut'] !== 'erreur' && ! empty($l['produit_id']))
            ->map(function (array $l) {
                $produit = $this->produitsParId->get($l['produit_id']);
                if (! $produit) {
                    return null;
                }
                $variante = $produit->variantes->first();

                return [
                    $variante?->sku,
                    ImportProduitsCellSanitizer::neutraliser($produit->nom),
                    $produit->produitType?->code,
                    $produit->categorie?->reference,
                    $produit->fournisseur?->reference,
                    $produit->statut?->value,
                    $variante?->code_barres,
                    $variante?->prix_achat,
                    $variante?->prix_usine,
                    $variante?->prix_usine_tricycle,
                    $variante?->prix_vente,
                    $variante?->prix_externe,
                    $variante?->prix_revendeur,
                    $variante?->prix_distributeur,
                    $variante?->cout,
                    $produit->alerte_stock_active ? 'oui' : 'non',
                    $produit->seuil_alerte_stock,
                    ImportProduitsCellSanitizer::neutraliser($produit->description),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->freezePane('A2');
                $sheet->setAutoFilter('A1:R1');
                foreach (['A', 'C', 'D', 'E', 'G'] as $col) {
                    $sheet->getStyle("{$col}2:{$col}501")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
                }
                $sheet->getColumnDimension('I')->setWidth(32);
                $sheet->getColumnDimension('J')->setWidth(24);
            },
        ];
    }
}
