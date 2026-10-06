<?php

namespace App\Http\Controllers\User;

use App\Features\ModuleFeature;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Agents\AgentDepensesService;
use App\Services\Agents\AgentSituationService;
use App\Services\ModuleService;
use App\Services\Rapports\RapportPerimetreResolver;
use App\Support\Permissions\RoleVisibility;
use App\Support\Rapports\RapportPerimetre;
use App\Support\Vehicules\SituationPeriode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fiche agent (docs/fiche-agent.md) : consultation du compte, avec les onglets Informations,
 * Mot de passe, Situation et Dépenses. L'édition reste sur users.edit. Chaque onglet sensible est
 * calculé ici selon les droits du consulteur : null = onglet masqué.
 */
class ShowUserController extends Controller
{
    public function __invoke(
        Request $request,
        User $user,
        RapportPerimetreResolver $perimetres,
        AgentSituationService $situation,
        AgentDepensesService $depenses,
    ): Response {
        $this->authorize('view', $user);

        $consulteur = $request->user();
        $user->load(['personne', 'roles:id,name', 'organization', 'sites' => fn ($q) => $q->orderBy('nom')]);

        $role = $user->getRoleNames()->first();
        // Libellé lu dans l'organisation de l'agent (console super admin : autre organisation).
        $roleLabel = $role && $user->organization_id
            ? RoleVisibility::query($user->organization_id)->where('name', $role)->value('label')
            : null;

        $periode = SituationPeriode::depuisRequete($request);
        $perimetre = $perimetres->pourFicheAgent($consulteur, $user, $periode);

        $peutVoirDepenses = $consulteur->can('depenses.read')
            && $user->organization !== null
            && ModuleService::isActive(ModuleFeature::DEPENSES, $user->organization);

        return Inertia::render('Users/Show', [
            'user' => [
                'id' => $user->id,
                'prenom' => $user->prenom,
                'nom' => $user->nom,
                'nom_complet' => $user->name,
                'matricule' => $user->matricule,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'code_phone_pays' => $user->code_phone_pays,
                'code_pays' => $user->code_pays,
                'pays' => $user->pays,
                'ville' => $user->ville,
                'adresse' => $user->adresse,
                'role' => $role,
                'role_label' => $roleLabel ?? $role,
                'is_active' => (bool) $user->is_active,
                'is_pending_validation' => $user->isPendingValidation(),
                'sites' => $user->sites->map(fn ($s) => [
                    'id' => $s->id,
                    'nom' => $s->nom,
                    'is_default' => (bool) $s->pivot->is_default,
                ])->values()->all(),
            ],
            'is_me' => $user->id === $consulteur->id,
            'peut_modifier' => $consulteur->can('update', $user),
            'situation' => $perimetre !== null ? $situation->pourPerimetre($perimetre) : null,
            'situation_periode' => $periode->pourFront(),
            'lien_rapport' => $perimetre !== null ? $this->lienRapport($perimetre, $periode) : null,
            'depenses' => $peutVoirDepenses ? $depenses->pourAgent($user) : null,
        ]);
    }

    /**
     * Détail ligne à ligne des chiffres de la Situation : « Ma situation » pour sa propre fiche,
     * le rapport d'activité filtré sur l'agent sinon. Le rapport ne propose pas « Toute la
     * période » : pas de lien dans ce cas plutôt qu'un lien qui afficherait un autre périmètre.
     */
    private function lienRapport(RapportPerimetre $perimetre, SituationPeriode $periode): ?string
    {
        if ($periode->debut === null || $periode->fin === null) {
            return null;
        }

        $dates = ['date_from' => $periode->debut->toDateString(), 'date_to' => $periode->fin->toDateString()];

        return $perimetre->maSituation
            ? route('ma-situation', $dates, false)
            : route('rapports.activite', [...$dates, 'agent_id' => $perimetre->agentId], false);
    }
}
