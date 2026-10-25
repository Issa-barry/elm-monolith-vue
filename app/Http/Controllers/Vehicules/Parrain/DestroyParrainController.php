<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Commission\PartageParrainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Retire le parrain du véhicule (sa Personne et son rôle Parrain restent, il peut parrainer
 * d'autres véhicules). Sa ligne disparaît des partages, effet le jour même ; il conserve toutes les
 * commissions déjà acquises. Une part positive revient au livreur seul, ou laisse le partage
 * « À faire » avec plusieurs livreurs (PARRAIN-024, cf. PartageParrainService).
 */
class DestroyParrainController extends Controller
{
    public function __invoke(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);
        abort_if(! $vehicule->parrain_id, 404, "Ce véhicule n'a pas de parrain à retirer.");

        // Modifier la répartition de l'équipe : même droit que la modale Équipe (PARRAIN-016).
        if (PartageParrainService::aUnePartPositive($vehicule)) {
            abort_unless($request->user()->can('equipes-livraison.update'), 403);
        }

        $aRepartir = DB::transaction(function () use ($vehicule) {
            $vehicule = Vehicule::whereKey($vehicule->id)->lockForUpdate()->firstOrFail();
            $ancienParrainId = $vehicule->parrain_id;
            $vehicule->update(['parrain_id' => null]);

            return PartageParrainService::appliquerChangementDeParrain($vehicule, $ancienParrainId);
        });

        return ParrainRedirection::apresChangement($vehicule, 'Parrain retiré du véhicule.', $aRepartir);
    }
}
