<?php

namespace App\Http\Controllers\Rh\Contrats;

use App\Http\Controllers\Controller;
use App\Models\Contrat;
use App\Services\Rh\DocumentContratService;
use App\Support\Rh\Contrats\VariablesContrat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * « Générer le PDF » (ADR 0044) : nouvelle version du contrat, à partir du modèle actif du type.
 * Accès en écriture : `rh-contrats.update`, `rh-remunerations.read` et agence du salarié dans les
 * agences de rattachement.
 */
class GenererDocumentContratController extends Controller
{
    use VerifieAccesContrat;

    public function __invoke(Request $request, Contrat $contrat, DocumentContratService $documents): RedirectResponse
    {
        $this->verifierAccesContrat($request->user(), $contrat, ecriture: true);

        $regles = ['avantages' => ['array', 'max:50'], 'avantages.*' => ['string', 'max:26']];
        foreach (array_keys(VariablesContrat::CHAMPS_SAISIS) as $champ) {
            $regles["champs.$champ"] = ['nullable', 'string', 'max:500'];
        }
        $data = $request->validate($regles, ['champs.*.max' => 'Chaque champ est limité à 500 caractères.']);

        $document = $documents->generer($contrat, $data['champs'] ?? [], $data['avantages'] ?? [], $request->user());

        return back()->with('success', 'Contrat généré (version '.$document->version.'). Il reste à le faire signer.');
    }
}
