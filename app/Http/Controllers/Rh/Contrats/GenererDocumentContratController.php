<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Http\Controllers\Controller;
use App\Models\Contrat;
use App\Services\Rh\DocumentContratService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * « Mettre à jour le PDF » (ADR 0048) : nouvelle tentative après un échec, ou nouvelle version à
 * faire signer après une version signée. Le PDF est d'ordinaire produit tout seul à la création et
 * à la modification du contrat ; rien n'est créé si le contenu n'a pas changé. Accès en écriture :
 * `rh-contrats.update`, `rh-remunerations.read` et agence du salarié dans les agences de
 * rattachement.
 */
class GenererDocumentContratController extends Controller
{
    use VerifieAccesContrat;

    public function __invoke(Request $request, Contrat $contrat, DocumentContratService $documents): RedirectResponse
    {
        $this->verifierAccesContrat($request->user(), $contrat, ecriture: true);

        try {
            $document = $documents->actualiser($contrat, $request->user(), apresSignature: true);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', DocumentContratService::MESSAGE_ECHEC_TECHNIQUE);
        }

        return back()->with('success', $document
            ? 'PDF du contrat prêt (version '.$document->version.'). Il reste à le faire signer.'
            : 'Le PDF est déjà à jour.');
    }
}
