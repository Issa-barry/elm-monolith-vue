<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepenseTypeImportRequest;
use App\Models\DepenseType;
use App\Services\ImportDepenseTypes\DepenseTypeImportExecutor;
use App\Services\ImportDepenseTypes\DepenseTypeImportParser;
use App\Services\ImportDepenseTypes\DepenseTypeImportTemplateExport;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import en masse des types de dépense — Dialog depuis Depenses/Types/Index.vue
 * (pas de page dédiée, pas d'enregistrement persisté entre l'aperçu et la
 * confirmation : même choix que SiteImportController, volume et complexité
 * comparables). Le fichier est ré-analysé à chaque appel — jamais de
 * confiance dans un aperçu déjà affiché côté client — voir
 * DepenseTypeImportExecutor::executer().
 */
class DepenseTypeImportController extends Controller
{
    public function modele(): mixed
    {
        abort_if(! auth()->user()->can('create', DepenseType::class), 403);

        return Excel::download(new DepenseTypeImportTemplateExport, 'modele-import-types-depense.xlsx');
    }

    public function analyser(StoreDepenseTypeImportRequest $request, DepenseTypeImportParser $parser): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $analyse = $parser->analyserFichier($request->file('fichier')->getRealPath(), $orgId);

        return response()->json($this->toResponse($analyse));
    }

    public function confirmer(StoreDepenseTypeImportRequest $request, DepenseTypeImportExecutor $executor): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $resultat = $executor->executer($request->file('fichier')->getRealPath(), $orgId);

        $reponse = $this->toResponse($resultat['analyse']);
        $reponse['execute'] = $resultat['succes'];
        if ($resultat['succes']) {
            $reponse['crees'] = $resultat['compteurs']['crees'];
        }

        return response()->json($reponse);
    }

    private function toResponse(array $analyse): array
    {
        $lignes = $analyse['lignes'];
        $nbErreur = count(array_filter($lignes, fn ($l) => $l['statut'] === 'erreur'));

        return [
            'nb_lignes_total' => $analyse['nb_lignes_total'],
            'nb_nouveaux' => count($lignes) - $nbErreur,
            'nb_erreurs' => $nbErreur,
            'lignes' => $lignes,
        ];
    }
}
