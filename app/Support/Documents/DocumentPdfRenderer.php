<?php

namespace App\Support\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Http\Response;

/** Rendu PDF commun à toutes les factures et à tous les bons (modèle officiel partagé). */
final class DocumentPdfRenderer
{
    public static function pdf(DocumentPdf $document): DomPdf
    {
        return Pdf::loadView($document->vue, ['document' => $document])
            ->setPaper('a4', 'portrait')
            // Poppins : ascender 1.05 + descender 0.35. DomPDF multiplie l'interligne CSS par la
            // hauteur de la fonte ; ce ratio conserve les interlignes du modèle Apollo.
            ->setOption('fontHeightRatio', 1 / 1.4)
            ->setCallbacks(DocumentPdfPagination::callbacks());
    }

    /** Téléchargement (pièce jointe), ou affichage dans le navigateur pour un aperçu. */
    public static function reponse(DocumentPdf $document, bool $apercu = false): Response
    {
        $pdf = self::pdf($document);

        return $apercu ? $pdf->stream($document->nomFichier()) : $pdf->download($document->nomFichier());
    }
}
