<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Enums\StatutContrat;
use App\Http\Controllers\Controller;
use App\Models\Contrat;
use App\Services\Rh\DocumentContratService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PDF de la dernière version du contrat (docs/rh-contrat-pdf.md, ADR 0044, 0048) : « Voir » de la
 * liste l'ouvre avec `?preview=1` (affiché dans le navigateur), sinon téléchargement. Pour qui peut mettre le contrat à jour, une
 * version non signée dépassée est d'abord remise à jour : on n'ouvre jamais un ancien PDF d'un
 * contrat modifié. 404 tant qu'aucune version n'existe.
 */
class TelechargerContratController extends Controller
{
    use VerifieAccesContrat;

    public function __invoke(Request $request, Contrat $contrat, DocumentContratService $documents): Response
    {
        $this->verifierAccesContrat($request->user(), $contrat);

        if ($contrat->statut_contrat === StatutContrat::ACTIF && $this->aAccesContrat($request->user(), $contrat, ecriture: true)) {
            $documents->genererAutomatiquement($contrat, $request->user());
        }

        $document = $contrat->documentCourant()->first();
        abort_if($document === null, 404, 'Aucun contrat généré pour le moment.');

        return $this->reponsePdf($document, $request->boolean('preview'));
    }
}
