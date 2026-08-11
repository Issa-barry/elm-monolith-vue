<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSiteImportRequest;
use App\Models\Site;
use App\Services\ImportSites\SiteImportExecutor;
use App\Services\ImportSites\SiteImportParser;
use App\Services\ImportSites\SiteImportTemplateExport;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import en masse des sites — Dialog depuis Sites/Index.vue (pas de page
 * dédiée : contrairement à ImportFlotteController, aucun enregistrement n'est
 * persisté entre l'aperçu et la confirmation, le volume et la complexité
 * métier ne le justifient pas ici). Le fichier est ré-analysé à chaque appel
 * (jamais de confiance dans un aperçu déjà affiché côté client) — voir
 * SiteImportExecutor::executer().
 */
class SiteImportController extends Controller
{
    public function modele(): mixed
    {
        abort_if(! auth()->user()->can('create', Site::class), 403);

        return Excel::download(new SiteImportTemplateExport, 'modele-import-sites.xlsx');
    }

    public function analyser(StoreSiteImportRequest $request, SiteImportParser $parser): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $analyse = $parser->analyserFichier($request->file('fichier')->getRealPath(), $orgId);

        return response()->json($this->toResponse($analyse));
    }

    public function confirmer(StoreSiteImportRequest $request, SiteImportExecutor $executor): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $resultat = $executor->executer($request->file('fichier')->getRealPath(), $orgId);

        $reponse = $this->toResponse($resultat['analyse']);
        $reponse['execute'] = $resultat['succes'];
        if ($resultat['succes']) {
            $reponse['crees'] = $resultat['compteurs']['crees'];
            $reponse['existants_ignores'] = $resultat['compteurs']['existants_ignores'];
        }

        return response()->json($reponse);
    }

    private function toResponse(array $analyse): array
    {
        $lignes = $analyse['lignes'];
        $nbErreur = count(array_filter($lignes, fn ($l) => $l['statut'] === 'erreur'));
        $nbExistant = count(array_filter($lignes, fn ($l) => $l['statut'] === 'existant'));

        return [
            'nb_lignes_total' => $analyse['nb_lignes_total'],
            'nb_nouveaux' => count($lignes) - $nbErreur - $nbExistant,
            'nb_existants' => $nbExistant,
            'nb_erreurs' => $nbErreur,
            'lignes' => $lignes,
        ];
    }
}
