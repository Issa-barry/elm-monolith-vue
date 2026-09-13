<?php

namespace App\Support\User;

use App\Enums\StatutEmploye;
use App\Enums\TypeEmploye;
use App\Models\FonctionRh;
use App\Models\User;

/**
 * Props de la page Users/Index — extrait de l'ancien `UserController::indexProps()` pour être
 * réutilisé par `Account\IndexAccountController` (écran "Comptes"), qui délègue à cette même
 * liste organisation-scopée pour tout acteur non super_admin, afin de ne pas dupliquer la logique
 * staff/rôles/validation entre les deux écrans.
 */
final class UserIndexPayload
{
    public static function build(User $authUser): array
    {
        $orgId = $authUser->organization_id;

        $users = User::with([
            'personne', 'authIdentities', 'roles:id,name',
            'sites' => fn ($q) => $q->wherePivot('is_default', true)->select('sites.id', 'sites.nom', 'sites.code')->limit(1),
        ])
            ->where('organization_id', $orgId)
            // "Staff" = porte un rôle qui n'est pas un rôle externe (même définition que
            // User::hasBackofficeAccess()) — remplace whereIn(STAFF_ROLES), qui excluait
            // silencieusement de cette liste tout utilisateur affecté à un rôle personnalisé
            // créé via Role\StoreRoleController (cf. refonte rôles/personnalisés assignables).
            ->whereHas('roles', fn ($q) => $q->whereNotIn('name', User::EXTERNAL_ROLES))
            ->get()
            ->sortBy('nom')
            ->map(function (User $u) {
                $defaultSite = $u->sites->first();

                return [
                    'id' => $u->id,
                    'nom' => $u->nom,
                    'prenom' => $u->prenom,
                    'nom_complet' => $u->name,
                    'email' => $u->email,
                    'telephone' => $u->telephone,
                    'code_phone_pays' => ($u->code_pays && isset(UserFormOptions::PAYS[$u->code_pays]))
                        ? UserFormOptions::PAYS[$u->code_pays][1]
                        : null,
                    'matricule' => $u->matricule,
                    'is_active' => $u->is_active,
                    'is_pending_validation' => $u->isPendingValidation(),
                    'roles' => $u->getRoleNames(),
                    'site' => $defaultSite ? "{$defaultSite->nom} ({$defaultSite->code})" : null,
                    'site_id' => $defaultSite?->id,
                    'is_me' => $u->id === auth()->id(),
                ];
            })
            ->values();

        $pendingRegistrations = collect();
        if ($authUser->isSuperAdmin()) {
            $pendingRegistrations = User::with(['personne', 'authIdentities'])
                ->whereNull('organization_id')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'nom' => $u->nom,
                    'prenom' => $u->prenom,
                    'nom_complet' => $u->name,
                    'email' => $u->email,
                    'telephone' => $u->telephone,
                    'email_verified' => $u->emailIdentity()?->isVerified() ?? false,
                    'created_at' => $u->created_at?->format('d/m/Y H:i'),
                ]);
        }

        return [
            'users' => $users,
            'pending_registrations' => $pendingRegistrations,
            // Options du modal de validation de compte (ValidateAccountModal.vue) — cf.
            // AccountValidationService. Vides si l'organisation n'a pas encore de site/fonction ;
            // le front affiche alors un état invitant à en créer un avant de pouvoir valider un
            // membre du personnel.
            'validation_role_options' => $orgId ? UserFormOptions::validationRoleOptions($orgId, $authUser->isSuperAdmin()) : [],
            'validation_site_options' => $orgId ? UserFormOptions::getSiteOptions($orgId) : [],
            'validation_fonction_options' => $orgId
                ? FonctionRh::where('organization_id', $orgId)->where('is_active', true)->orderBy('libelle')
                    ->get(['id', 'libelle'])->map(fn (FonctionRh $f) => ['value' => $f->id, 'label' => $f->libelle])
                : [],
            'type_employe_options' => TypeEmploye::options(),
            'statut_employe_options' => StatutEmploye::options(),
            // Le libellé de rôle (colonne "Rôle" de cette liste, via <RoleBadges>) vient
            // désormais de auth.role_labels (partagé globalement par HandleInertiaRequests,
            // même scope organisation ∪ système) — plus besoin d'une prop dédiée ici.
        ];
    }
}
