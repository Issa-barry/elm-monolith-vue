<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Commission\PartageParrainService;
use App\Support\Parrainage\RepartitionsParrain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Retire le parrain du véhicule (sa Personne et son rôle Parrain restent, il peut parrainer
 * d'autres véhicules). Sa ligne disparaît des partages, effet le jour même ; s'il recevait une part
 * positive, la nouvelle répartition de l'équipe doit être jointe et validée dans la même
 * transaction — cf. PartageParrainService.
 */
class DestroyParrainController extends Controller
{
    public function __invoke(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);
        abort_if(! $vehicule->parrain_id, 404, "Ce véhicule n'a pas de parrain à retirer.");

        $repartitions = RepartitionsParrain::valider($request);

        DB::transaction(function () use ($vehicule, $repartitions) {
            $ancienParrainId = $vehicule->parrain_id;
            $vehicule->update(['parrain_id' => null]);

            PartageParrainService::appliquerChangementDeParrain($vehicule, $ancienParrainId, $repartitions);
        });

        return redirect()->route('vehicules.show', $vehicule)
            ->with('success', 'Parrain retiré du véhicule.');
    }
}
