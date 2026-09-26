<?php

namespace App\Http\Controllers\User;

use App\Enums\StatutEmploye;
use App\Enums\TypeEmploye;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Rh\AccountValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ValidateAccountUserController extends Controller
{
    /**
     * PATCH /users/{user}/validate
     * Valide un compte en attente : choix du profil d'accès, du site, et — pour un membre du
     * personnel — de la fonction RH (avec création ou rattachement de la fiche Employe). Toute la
     * logique transactionnelle vit dans AccountValidationService (cf. plan "fonctions RH/profils
     * d'accès/sites") — ce contrôleur ne fait que valider la requête et déléguer.
     */
    public function __invoke(Request $request, User $user, AccountValidationService $service): RedirectResponse
    {
        $this->authorize('update', $user);

        $isStaff = $request->boolean('is_staff_avec_fiche_employe');

        // role_id/site_id restent optionnels : ValidateAccountModal.vue les envoie toujours,
        // mais un appel sans corps (comportement historique, cf. AccountValidationTest) doit
        // continuer à fonctionner — AccountValidationService retombe alors sur le rôle/site déjà
        // posés sur le compte par UserInvitationService::accept().
        $data = $request->validate([
            'is_staff_avec_fiche_employe' => 'boolean',
            'role_id' => 'nullable|integer|exists:roles,id',
            'site_id' => 'nullable|string|exists:sites,id',
            'fonction_rh_id' => [$isStaff ? 'required' : 'nullable', 'string', 'exists:fonctions_rh,id'],
            'type_employe' => [$isStaff ? 'required' : 'nullable', Rule::in(TypeEmploye::values())],
            'statut' => [$isStaff ? 'required' : 'nullable', Rule::in(StatutEmploye::values())],
        ], [
            'fonction_rh_id.required' => 'La fonction RH est obligatoire pour un membre du personnel.',
            'type_employe.required' => 'Le type d\'employé est obligatoire pour un membre du personnel.',
            'statut.required' => 'Le statut est obligatoire pour un membre du personnel.',
        ]);

        $service->valider($user, $data, auth()->user());

        // Retourne vers la page d'origine (liste des utilisateurs ou onglet
        // "Membres" d'un site) plutôt qu'une destination fixe.
        return back()->with('success', "{$user->name} a été validé et peut désormais se connecter.");
    }
}
