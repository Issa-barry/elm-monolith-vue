<?php

namespace App\Http\Controllers\Vehicules\Parrain;

use App\Http\Controllers\Controller;
use App\Models\Vehicule;
use App\Services\Commission\PartParrainService;
use App\Services\Commission\RecalculRetroactifParrainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Saisie en place de la part du parrain dans l'onglet Parrain (PARRAIN-024) : une part par partage
 * où il est rémunéré, confirmation obligatoire d'une part nulle (PARRAIN-022), date de début des
 * droits (PARRAIN-023). Un seul livreur reçoit le reste du barème ; avec plusieurs livreurs, la
 * répartition est à refaire dans l'onglet Équipe. Modifier la répartition de l'équipe : même droit
 * que la modale Équipe (`equipes-livraison.update`).
 */
class DefinirPartParrainController extends Controller
{
    public function __invoke(Request $request, Vehicule $vehicule): RedirectResponse
    {
        $this->authorize('update', $vehicule);
        abort_unless($request->user()->can('equipes-livraison.update'), 403);

        $data = $request->validate([
            'parrain_id' => ['required', 'string'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.processus_code' => ['required', 'string'],
            'parts.*.categorie_id' => ['required', 'string'],
            'parts.*.part_parrain' => ['required', 'integer', 'min:0'],
            'confirmer_part_nulle' => ['boolean'],
            'date_effet' => ['nullable', 'date_format:Y-m-d'],
            'remplacer_planification' => ['boolean'],
        ], [
            'date_effet.date_format' => 'La date de début des droits doit être une date valide.',
            'parts.required' => 'Aucune part à enregistrer.',
            'parts.*.part_parrain.required' => 'La part du parrain doit être renseignée (0 GNF accepté).',
            'parts.*.part_parrain.min' => 'La part du parrain ne peut pas être négative.',
        ]);

        $dateEffet = isset($data['date_effet']) ? Carbon::createFromFormat('Y-m-d', $data['date_effet'])->startOfDay() : null;
        $resultat = PartParrainService::definirParts(
            $vehicule,
            $data['parrain_id'],
            $data['parts'],
            (bool) ($data['confirmer_part_nulle'] ?? false),
            $dateEffet,
            (bool) ($data['remplacer_planification'] ?? false),
        );

        $message = 'Part du parrain enregistrée.';
        if ($resultat['bilan'] !== null) {
            $message .= ' '.RecalculRetroactifParrainService::resume($resultat['bilan'], $dateEffet);
        } elseif ($dateEffet?->isFuture()) {
            $message .= ' Elle prendra effet le '.$dateEffet->format('d/m/Y').'.';
        }

        $redirect = redirect()->route('vehicules.show', ['vehicule' => $vehicule, 'tab' => 'parrain'])
            ->with('success', $message);

        if ($resultat['a_repartir'] !== []) {
            $redirect->with('warning', sprintf(
                'Répartition de l\'équipe à refaire dans l\'onglet Équipe : %s. Les ventes concernées sont refusées d\'ici là.',
                implode(', ', $resultat['a_repartir']),
            ));
        }

        return $redirect;
    }
}
