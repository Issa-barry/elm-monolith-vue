<?php

namespace App\Http\Controllers\Produits\Imports;

use App\Enums\StatutImportProduits;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportProduitsRequest;
use App\Models\ImportProduits;
use App\Support\Produits\Imports\ImportProduitsStatusProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreImportProduitsController extends Controller
{
    public function __invoke(StoreImportProduitsRequest $request): RedirectResponse
    {
        $orgId = $request->user()->organization_id;
        abort_if(! $orgId, 403);

        $fichier = $request->file('fichier');
        $nomOriginal = $fichier->getClientOriginalName();
        $extension = $fichier->getClientOriginalExtension() ?: 'xlsx';
        $chemin = "imports-produits/{$orgId}/".Str::uuid().'.'.$extension;

        $fichier->storeAs(dirname($chemin), basename($chemin), 'local');

        $import = ImportProduits::create([
            'organization_id' => $orgId,
            'user_id' => $request->user()->id,
            'fichier_original' => $nomOriginal,
            'fichier_path' => $chemin,
            'fichier_hash' => hash_file('sha256', Storage::disk('local')->path($chemin)) ?: null,
            'statut' => StatutImportProduits::ANALYSE->value,
        ]);

        $analyseReussie = ImportProduitsStatusProcessor::analyser($import);

        return redirect()->route('produits.imports.show', $import)->with(
            $analyseReussie ? 'success' : 'error',
            $analyseReussie
                ? "Fichier analysé. Vérifiez l'aperçu avant de confirmer."
                : "Le fichier n'a pas pu être analysé — voir le détail ci-dessous."
        );
    }
}
