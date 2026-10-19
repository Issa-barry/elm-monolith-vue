<?php

namespace App\Support\Documents;

use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Dompdf\Frame;

/**
 * « Page X / Y » dans la cellule `.pagination` du pied de page du modèle officiel : DomPDF ne
 * connaît pas le nombre total de pages pendant la mise en page (counter(pages) vaut 0), le texte
 * est donc dessiné une fois le document terminé, aligné à droite dans la cellule repérée.
 */
final class DocumentPdfPagination
{
    /** @return array<int, array{event: string, f: \Closure}> */
    public static function callbacks(): array
    {
        $cellules = [];

        return [
            [
                'event' => 'end_frame',
                'f' => static function (Frame $frame, Canvas $canvas) use (&$cellules): void {
                    $node = $frame->get_node();
                    if ($node instanceof \DOMElement && in_array('pagination', explode(' ', $node->getAttribute('class')), true)) {
                        $cellules[$canvas->get_page_number()] = $frame->get_padding_box();
                    }
                },
            ],
            [
                'event' => 'end_document',
                'f' => static function (int $page, int $total, Canvas $canvas, FontMetrics $metrics) use (&$cellules): void {
                    $cellule = $cellules[$page] ?? null;
                    if (! $cellule) {
                        return;
                    }
                    $police = $metrics->getFont('PoppinsInvoice', 'normal') ?? $metrics->getFont('DejaVu Sans', 'normal');
                    $taille = 7.875;
                    $texte = 'Page '.$page.' / '.$total;
                    $largeur = $metrics->getTextWidth($texte, $police, $taille);
                    // Aligné sur la dernière ligne de la mention : 1.2 = line-height du pied ; text() place
                    // le haut de la boîte de la police, 0.42 hauteur au-dessus des glyphes (mesuré).
                    $hauteur = $metrics->getFontHeight($police, $taille);
                    $haut = $cellule['y'] + $cellule['h'] - 1.2 * $hauteur - 0.42 * $hauteur;
                    $canvas->text($cellule['x'] + $cellule['w'] - $largeur, $haut, $texte, $police, $taille, [100 / 255, 116 / 255, 139 / 255]);
                },
            ],
        ];
    }
}
