<?php

namespace App\Http\Controllers\Contacts\Fusions;

use App\Http\Controllers\Controller;
use App\Models\ContactsFusion;
use App\Models\Personne;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Historique des fusions de l'organisation (lot 9b), des plus récentes aux plus anciennes. */
class IndexFusionController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $this->authorize('fusionner', Personne::class);

        $fusions = ContactsFusion::with('acteur.personne')
            ->where('organization_id', $request->user()->organization_id)
            ->latest()
            ->paginate(25)
            ->through(fn (ContactsFusion $f) => [
                'id' => $f->id,
                'type' => $f->type,
                'conservee' => $f->simulation['a']['nom'] ?? '—',
                'absorbee' => $f->simulation['b']['nom'] ?? '—',
                'acteur' => $f->acteur?->personne?->nom_complet,
                'date' => $f->created_at?->format('d/m/Y H:i'),
                'statut' => $f->statut,
                'statut_label' => ContactsFusion::LIBELLES_STATUT[$f->statut] ?? $f->statut,
            ]);

        return Inertia::render('Contacts/Fusions/Index', ['fusions' => $fusions]);
    }
}
