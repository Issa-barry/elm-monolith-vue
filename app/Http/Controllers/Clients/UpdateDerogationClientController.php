<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\DerogationImpayesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Active/désactive la dérogation ET son plafond, atomiquement, directement depuis la fiche
 * client (Clients/Show.vue) — même schéma que VehiculeController::updateDerogation(), même règle
 * de cohérence (DerogationImpayesService, mutualisée). `seuil_derogation_impayes` est facultatif
 * dans la requête : omis, le plafond déjà enregistré en base est conservé tel quel (ex: réactiver
 * une dérogation précédemment désactivée sans ressaisir son montant).
 */
class UpdateDerogationClientController extends Controller
{
    public function __invoke(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'derogation_impayes_autorisee' => 'required|boolean',
            'seuil_derogation_impayes' => 'nullable|integer|min:0|max:999999999',
        ]);

        $seuil = array_key_exists('seuil_derogation_impayes', $data) && $request->filled('seuil_derogation_impayes')
            ? $data['seuil_derogation_impayes']
            : $client->seuil_derogation_impayes;

        DerogationImpayesService::validerCoherence(
            $data['derogation_impayes_autorisee'],
            $seuil,
            $client->organization_id,
            'ce client',
        );

        $client->update([
            'derogation_impayes_autorisee' => $data['derogation_impayes_autorisee'],
            'seuil_derogation_impayes' => $seuil,
        ]);

        $label = $data['derogation_impayes_autorisee'] ? 'activée' : 'désactivée';

        return back()->with('success', "Dérogation impayés {$label}.");
    }
}
