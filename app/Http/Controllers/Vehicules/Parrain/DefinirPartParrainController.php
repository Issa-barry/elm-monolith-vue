<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Commission\PartParrainService;
use App\Services\Commission\RecalculRetroactifParrainService;
use App\Support\Parrainage\RepartitionsParrain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Enregistre la part du parrain sur tous ses partages en une fois (PARRAIN-021) : répartitions
 * complètes et conformes, confirmation obligatoire d'une part entièrement nulle. Modifier la
 * répartition de l'équipe : même droit que la modale Équipe (`equipes-livraison.update`).
 */
class DefinirPartParrainController extends Controller
{
    public function __invoke(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);
        abort_unless($request->user()->can('equipes-livraison.update'), 403);

        $data = $request->validate([
            'parrain_id' => ['required', 'string'],
            'repartitions' => ['required', 'array', 'min:1'],
            'repartitions.*.part_parrain' => ['required', 'integer', 'min:0'],
            'confirmer_part_nulle' => ['boolean'],
            // PARRAIN-023 : date de début des droits (aujourd'hui par défaut, passée ou future).
            'date_effet' => ['nullable', 'date_format:Y-m-d'],
            'remplacer_planification' => ['boolean'],
        ], [
            'date_effet.date_format' => 'La date de début des droits doit être une date valide.',
            'repartitions.required' => 'Aucune répartition à enregistrer.',
            'repartitions.*.part_parrain.required' => 'La part du parrain doit être renseignée (0 GNF accepté).',
        ]);

        $dateEffet = isset($data['date_effet']) ? Carbon::createFromFormat('Y-m-d', $data['date_effet'])->startOfDay() : null;
        $bilan = PartParrainService::definir(
            $vehicule,
            $data['parrain_id'],
            RepartitionsParrain::valider($request),
            (bool) ($data['confirmer_part_nulle'] ?? false),
            $dateEffet,
            (bool) ($data['remplacer_planification'] ?? false),
        );

        $message = 'Part du parrain enregistrée.';
        if ($bilan !== null) {
            $message .= ' '.RecalculRetroactifParrainService::resume($bilan, $dateEffet);
        } elseif ($dateEffet?->isFuture()) {
            $message .= ' Elle prendra effet le '.$dateEffet->format('d/m/Y').'.';
        }

        return redirect()->route('vehicules.show', ['vehicule' => $vehicule, 'tab' => 'parrain'])
            ->with('success', $message);
    }
}
