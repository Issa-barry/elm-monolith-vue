<?php

namespace App\Http\Controllers;

use App\Http\Requests\Produits\StoreOptionCatalogueRequest;
use App\Http\Requests\Produits\StoreOptionCatalogueValeurRequest;
use App\Http\Requests\Produits\UpdateOptionCatalogueRequest;
use App\Models\OptionCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OptionCatalogueController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', OptionCatalogue::class);

        $orgId = auth()->user()->organization_id;

        $options = OptionCatalogue::where('organization_id', $orgId)
            ->orderBy('position')
            ->orderBy('nom')
            ->with('valeurs')
            ->get()
            ->map(fn (OptionCatalogue $o) => [
                'id' => $o->id,
                'nom' => $o->nom,
                'is_system' => $o->is_system,
                'position' => $o->position,
                'valeurs' => $o->valeurs->map(fn ($v) => [
                    'id' => $v->id,
                    'valeur' => $v->valeur,
                    'hex' => $v->hex,
                    'position' => $v->position,
                ]),
            ]);

        return Inertia::render('Produits/Options/Index', [
            'options' => $options,
        ]);
    }

    public function store(StoreOptionCatalogueRequest $request): RedirectResponse
    {
        $this->authorize('create', OptionCatalogue::class);

        $option = DB::transaction(function () use ($request) {
            $option = OptionCatalogue::create([
                'nom' => $request->validated('nom'),
                'position' => $request->validated('position') ?? 0,
                'organization_id' => auth()->user()->organization_id,
            ]);

            foreach ($request->input('valeurs', []) as $index => $valeur) {
                $option->valeurs()->create(['valeur' => $valeur, 'position' => $index]);
            }

            return $option;
        });

        // Permet à la création rapide depuis un formulaire Produit (modale, sans navigation)
        // de sélectionner automatiquement l'option tout juste créée — cf. OptionCatalogueSelect.vue.
        return back()
            ->with('success', 'Option créée avec succès.')
            ->with('created_option_catalogue_id', $option->id);
    }

    public function update(UpdateOptionCatalogueRequest $request, OptionCatalogue $option): RedirectResponse
    {
        $this->authorize('update', $option);

        $option->update($request->validated());

        return back()->with('success', 'Option mise à jour avec succès.');
    }

    public function destroy(OptionCatalogue $option): RedirectResponse
    {
        $this->authorize('delete', $option);

        if ($option->is_system) {
            return back()->withErrors([
                'delete' => "« {$option->nom} » est une option système proposée par défaut et ne peut pas être supprimée. Vous pouvez toujours ajuster ses valeurs.",
            ]);
        }

        $option->delete();

        return back()->with('success', 'Option supprimée.');
    }

    public function storeValeur(StoreOptionCatalogueValeurRequest $request, OptionCatalogue $option): RedirectResponse
    {
        $this->authorize('update', $option);

        $position = $option->valeurs()->max('position');

        $valeur = $option->valeurs()->create([
            'valeur' => $request->validated('valeur'),
            'hex' => $request->validated('hex'),
            'position' => $position === null ? 0 : $position + 1,
        ]);

        return back()
            ->with('success', 'Valeur ajoutée avec succès.')
            ->with('created_option_catalogue_valeur_id', $valeur->id);
    }

    public function destroyValeur(OptionCatalogue $option, string $valeur): RedirectResponse
    {
        $this->authorize('update', $option);

        $option->valeurs()->whereKey($valeur)->delete();

        return back()->with('success', 'Valeur supprimée.');
    }
}
