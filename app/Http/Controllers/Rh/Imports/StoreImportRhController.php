<?php

namespace App\Http\Controllers\Rh\Imports;

use App\Enums\StatutImportRh;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportRhRequest;
use App\Models\ImportRh;
use App\Services\ImportRh\ImportRhService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Dépôt d'un fichier : stocké sur le disque privé, analysé aussitôt (aucune écriture métier). */
class StoreImportRhController extends Controller
{
    public function __invoke(StoreImportRhRequest $request, ImportRhService $imports): RedirectResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403);

        $fichier = $request->file('fichier');
        $extension = $fichier->getClientOriginalExtension() ?: 'xlsx';
        $chemin = "imports-rh/{$orgId}/".Str::uuid().'.'.$extension;
        $fichier->storeAs(dirname($chemin), basename($chemin), 'local');

        try {
            $import = ImportRh::create([
                'organization_id' => $orgId,
                'user_id' => $request->user()->id,
                'fichier_original' => $fichier->getClientOriginalName(),
                'fichier_path' => $chemin,
                'statut' => StatutImportRh::ANALYSE->value,
            ]);

            $analyse = $imports->analyser($import, $request->user());
        } catch (Throwable $e) {
            // Le fichier peut contenir des salaires : jamais laissé sur le disque après un échec imprévu.
            Storage::disk('local')->delete($chemin);

            throw $e;
        }

        return redirect()->route('employes.imports.show', $import)->with(
            $analyse ? 'success' : 'error',
            $analyse
                ? "Fichier analysé. Vérifiez l'aperçu avant de confirmer."
                : "Le fichier n'a pas pu être analysé — voir le détail ci-dessous."
        );
    }
}
