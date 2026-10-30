<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Http\Controllers\Controller;
use App\Models\Contrat;
use App\Models\DocumentContrat;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Une version précise du contrat généré (historique), `?preview=1` pour l'aperçu (ADR 0044). */
class TelechargerDocumentContratController extends Controller
{
    use VerifieAccesContrat;

    public function __invoke(Request $request, Contrat $contrat, DocumentContrat $document): Response
    {
        $this->verifierAccesContrat($request->user(), $contrat);
        abort_unless($document->contrat_id === $contrat->id, 404);

        return $this->reponsePdf($document, $request->boolean('preview'));
    }
}
