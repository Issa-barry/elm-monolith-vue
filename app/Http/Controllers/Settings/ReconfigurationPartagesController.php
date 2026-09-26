<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CommissionBaremeBrouillon;
use App\Models\CommissionRegle;
use App\Services\Commission\ReconfigurationPartagesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reconfiguration groupée des partages Livreur d'un brouillon de barème (lot 2, ADR 0006) —
 * grille d'édition type tableur, puis publication atomique barème + partages. Toute la règle
 * métier vit dans ReconfigurationPartagesService ; ce contrôleur n'ajoute que les autorisations :
 *  - consulter : `parametres.read` (comme Paramètres → Commissions) ;
 *  - préparer des partages : `equipes-livraison.update` (ce sont des partages d'équipe) ;
 *  - publier : `parametres.update` ET `equipes-livraison.update` (écrit barème ET partages) ;
 *  - abandonner : `parametres.update`.
 */
class ReconfigurationPartagesController extends Controller
{
    public function show(Request $request, CommissionBaremeBrouillon $brouillon): Response|RedirectResponse
    {
        $this->authorize('viewAny', CommissionRegle::class);
        $this->assurerOrganisation($brouillon);

        $processus = $brouillon->processus;

        if (! $brouillon->estEnCours()) {
            return to_route('settings.commissions.index', ['processus' => $processus->code])
                ->with('success', 'Ce brouillon a déjà été publié ou abandonné.');
        }

        $groupes = ReconfigurationPartagesService::groupes($brouillon->organization_id, $processus, $brouillon->lignes, $brouillon);
        $user = $request->user();

        return Inertia::render('settings/CommissionRegles/Reconfiguration', [
            'brouillon' => [
                'id' => $brouillon->id,
                'processus_code' => $processus->code,
                'processus_label' => CommissionRegleController::processusLabel($processus->code),
                'createur' => $brouillon->createur?->name,
                'created_at' => $brouillon->created_at?->toIso8601String(),
                'updated_at' => $brouillon->updated_at?->toIso8601String(),
            ],
            'groupes' => $groupes->values(),
            'resume' => [
                'total' => $groupes->count(),
                'nb_equipes' => $groupes->pluck('equipe_id')->unique()->count(),
                'conformes' => $groupes->where('statut', ReconfigurationPartagesService::STATUT_CONFORME)->count(),
                'a_corriger' => $groupes->where('statut', ReconfigurationPartagesService::STATUT_A_CORRIGER)->count(),
                'a_revalider' => $groupes->where('statut', ReconfigurationPartagesService::STATUT_A_REVALIDER)->count(),
            ],
            'filters' => $request->only(['search', 'statut', 'categorie_id', 'type_vehicule_id', 'site_ids']),
            'permissions' => [
                'modifier' => $user->can('equipes-livraison.update'),
                'publier' => $user->can('parametres.update') && $user->can('equipes-livraison.update'),
                'abandonner' => $user->can('parametres.update'),
            ],
        ]);
    }

    public function enregistrerPartages(Request $request, CommissionBaremeBrouillon $brouillon): RedirectResponse
    {
        abort_unless($request->user()->can('equipes-livraison.update'), 403);
        $this->assurerOrganisation($brouillon);

        $data = $request->validate([
            'saisies' => ['required', 'array', 'min:1'],
            'saisies.*.equipe_id' => ['required', 'string'],
            'saisies.*.categorie_id' => ['required', 'string'],
            'saisies.*.parts' => ['required', 'array', 'min:1'],
            'saisies.*.parts.*.livreur_id' => ['required', 'string'],
            'saisies.*.parts.*.montant_unitaire' => ['required', 'integer', 'min:0', 'max:99999999'],
        ], [
            'saisies.*.parts.*.montant_unitaire.integer' => 'Chaque montant doit être un entier GNF, sans décimales.',
            'saisies.*.parts.*.montant_unitaire.min' => 'Un montant ne peut pas être négatif.',
        ]);

        ReconfigurationPartagesService::enregistrerPartages($brouillon, $data['saisies'], $request->user()->id);

        return back()->with('success', sprintf('%d partage(s) enregistré(s) dans le brouillon.', count($data['saisies'])));
    }

    public function publier(Request $request, CommissionBaremeBrouillon $brouillon): RedirectResponse
    {
        abort_unless($request->user()->can('parametres.update') && $request->user()->can('equipes-livraison.update'), 403);
        $this->assurerOrganisation($brouillon);

        $processusCode = $brouillon->processus->code;

        try {
            ReconfigurationPartagesService::publier($brouillon, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return to_route('settings.commissions.index', ['processus' => $processusCode])
            ->with('success', 'Nouveau barème et partages publiés : ils s’appliquent dès maintenant aux nouvelles commissions.');
    }

    public function abandonner(Request $request, CommissionBaremeBrouillon $brouillon): RedirectResponse
    {
        $this->authorize('create', CommissionRegle::class);
        $this->assurerOrganisation($brouillon);

        $processusCode = $brouillon->processus->code;
        if ($brouillon->estEnCours()) {
            ReconfigurationPartagesService::abandonner($brouillon, $request->user()->id);
        }

        return to_route('settings.commissions.index', ['processus' => $processusCode])
            ->with('success', 'Brouillon abandonné : le barème en vigueur reste inchangé.');
    }

    private function assurerOrganisation(CommissionBaremeBrouillon $brouillon): void
    {
        abort_unless($brouillon->organization_id === auth()->user()->organization_id, 404);
    }
}
