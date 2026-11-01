<?php

namespace App\Http\Controllers\Produits\Categories;

use App\Enums\CategorieStatut;
use App\Http\Controllers\Controller;
use App\Models\Categorie;
use App\Models\Vehicule;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class IndexCategorieController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', Categorie::class);

        $orgId = auth()->user()->organization_id;

        $categories = Categorie::where('organization_id', $orgId)
            ->orderBy('position')
            ->orderBy('nom')
            ->withCount('produits')
            ->get()
            ->map(fn (Categorie $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'reference' => $c->reference,
                'description' => $c->description,
                'statut' => $c->statut->value,
                'statut_label' => $c->statut->label(),
                'parent_id' => $c->parent_id,
                'position' => $c->position,
                'produits_count' => $c->produits_count,
                'capacite_vehicule_obligatoire' => $c->capacite_vehicule_obligatoire,
                'vehicules_sans_capacite_count' => $this->vehiculesVenteSansCapacite($orgId, $c->id),
            ]);

        return Inertia::render('Produits/Categories/Index', [
            'categories' => $categories,
            'statuts' => CategorieStatut::options(),
        ]);
    }

    /**
     * Véhicules actifs pouvant servir à une vente (usage Vente, Logistique pour la distribution
     * client, ou Grossiste) sans capacité configurée pour cette catégorie : ceux qui ne pourront
     * pas vendre ses produits si la capacité y est rendue obligatoire (ADR 0042).
     */
    private function vehiculesVenteSansCapacite(string $orgId, string $categorieId): int
    {
        return Vehicule::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->where('livraison_vente', true)
                ->orWhere('livraison_logistique', true)
                ->orWhere('livraison_grossiste', true))
            ->whereDoesntHave('capacites', fn (Builder $q) => $q->where('categorie_id', $categorieId))
            ->count();
    }
}
