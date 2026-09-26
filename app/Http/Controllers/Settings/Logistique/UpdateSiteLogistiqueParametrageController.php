<?php

namespace App\Http\Controllers\Settings\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Édite la dérogation d'UN site — indépendante du formulaire principal (chaque ligne du tableau
 * s'enregistre elle-même, pas de soumission groupée). `approbation_reception_logistique_obligatoire`
 * accepte explicitement `null` ("hérite du réglage organisation"), en plus de `true`/`false`
 * (dérogation explicite) — cf. Site::approbationReceptionObligatoireEffective().
 */
class UpdateSiteLogistiqueParametrageController extends Controller
{
    public function __invoke(Request $request, Site $site): RedirectResponse
    {
        abort_if(! auth()->user()->can('parametres.update'), 403);

        // Vérification cross-tenant explicite : {site} est lié par route sans scope organisation
        // (contrairement à UpdateLogistiqueParametrageController, qui n'agit que sur
        // l'organisation de l'acteur) — sans ce contrôle, un admin pourrait modifier la
        // dérogation d'un site d'une AUTRE organisation en devinant/rejouant son identifiant
        // (cf. CLAUDE.md §11).
        abort_if($site->organization_id !== auth()->user()->organization_id, 403);

        $validated = $request->validate([
            'approbation_reception_logistique_obligatoire' => ['nullable', 'boolean'],
        ]);

        $site->update([
            'approbation_reception_logistique_obligatoire' => $validated['approbation_reception_logistique_obligatoire'] ?? null,
        ]);

        return back()->with('success', 'Reglage du site mis a jour.');
    }
}
