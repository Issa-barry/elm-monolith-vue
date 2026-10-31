<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Http\Controllers\Controller;
use App\Models\Contrat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * « Voir / Télécharger le PDF » de la liste des contrats (docs/rh-contrat-pdf.md, ADR 0044) : la
 * dernière version générée, `?preview=1` pour l'aperçu. 404 tant qu'aucune version n'existe.
 */
class TelechargerContratController extends Controller
{
    use VerifieAccesContrat;

    public function __invoke(Request $request, Contrat $contrat): Response
    {
        $this->verifierAccesContrat($request->user(), $contrat);

        $document = $contrat->documentCourant()->first();
        abort_if($document === null, 404, 'Aucun contrat généré pour le moment.');

        return $this->reponsePdf($document, $request->boolean('preview'));
    }
}
