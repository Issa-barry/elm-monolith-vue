<?php

namespace App\Http\Controllers\Produits\Options;

use App\Http\Controllers\Controller;
use App\Models\OptionCatalogue;
use Inertia\Inertia;
use Inertia\Response;

class IndexOptionCatalogueController extends Controller
{
    public function __invoke(): Response
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
}
