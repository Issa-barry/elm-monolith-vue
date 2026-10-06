<?php

namespace App\Http\Controllers\Produits\Types;

use App\Enums\ProduitTypeStatut;
use App\Http\Controllers\Controller;
use App\Models\ProduitType;
use Inertia\Inertia;
use Inertia\Response;

class IndexProduitTypeController extends Controller
{
    public function __invoke(): Response
    {
        $this->authorize('viewAny', ProduitType::class);

        $orgId = auth()->user()->organization_id;

        $types = ProduitType::where('organization_id', $orgId)
            ->orderBy('position')
            ->orderBy('nom')
            ->withCount('produits')
            ->get()
            ->map(fn (ProduitType $t) => [
                'id' => $t->id,
                'nom' => $t->nom,
                'code' => $t->code,
                'statut' => $t->statut->value,
                'statut_label' => $t->statut->label(),
                'gere_stock' => $t->gere_stock,
                'vendable' => $t->vendable,
                'achetable' => $t->achetable,
                'prix_achat_requis' => $t->prix_achat_requis,
                'prix_usine_requis' => $t->prix_usine_requis,
                'prix_vente_requis' => $t->prix_vente_requis,
                'champ_prix_reference' => $t->champ_prix_reference,
                'position' => $t->position,
                'produits_count' => $t->produits_count,
                'is_used' => $t->produits_count > 0,
            ]);

        return Inertia::render('Produits/Types/Index', [
            'types' => $types,
            'statuts' => ProduitTypeStatut::options(),
        ]);
    }
}
