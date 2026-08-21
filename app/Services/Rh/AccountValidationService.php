<?php

namespace App\Services\Rh;

use App\Enums\AuditEvent;
use App\Mail\AccountValidatedMail;
use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\MatriculeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Orchestration transactionnelle de la validation d'un compte en attente : choix du profil
 * d'accès (rôle Spatie), du site, et — pour un membre du personnel — de la fonction RH, avec
 * création ou rattachement de la fiche Employe. Une seule DB::transaction() : aucune affectation
 * partielle ne subsiste en cas d'échec (rollback complet).
 *
 * `role_id`/`site_id` sont OPTIONNELS : `UserInvitationService::accept()` pré-assigne déjà un
 * rôle et un site provisoires à l'acceptation de l'invitation (avant validation admin) — les
 * omettre revalide simplement ce qui a déjà été posé (comportement historique préservé, cf.
 * AccountValidationTest, pré-existant), les fournir les REMPLACE explicitement. `is_active` déjà
 * vrai est un rejet 422 brut (pas une ValidationException, qui redirigerait au lieu de renvoyer
 * 422 sur une requête web standard) — comportement historique préservé à l'identique.
 */
class AccountValidationService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    /**
     * @param  array{is_staff_avec_fiche_employe?: bool, role_id?: ?int, site_id?: ?string, fonction_rh_id?: ?string, type_employe?: ?string, statut?: ?string}  $data
     */
    public function valider(User $user, array $data, User $validateur): void
    {
        DB::transaction(function () use ($user, $data, $validateur) {
            // lockForUpdate() + re-vérification DANS le verrou : anti double-validation et
            // concurrence (deux clics/requêtes simultanées sur le même compte).
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->isPendingValidation(), 422, "Ce compte n'est pas en attente de validation.");

            $orgId = $user->organization_id;

            $role = $this->resoudreRole($data['role_id'] ?? null, $user, $orgId, $validateur);
            $site = $this->resoudreSite($data['site_id'] ?? null, $user, $orgId);

            $isStaff = (bool) ($data['is_staff_avec_fiche_employe'] ?? false);
            $employe = null;
            $fonction = null;

            if ($isStaff) {
                $fonction = FonctionRh::where('organization_id', $orgId)->find($data['fonction_rh_id'] ?? null);
                if ($fonction === null) {
                    throw ValidationException::withMessages(['fonction_rh_id' => 'Fonction RH introuvable.']);
                }

                $employe = $this->resoudreOuCreerEmploye($user, $orgId, $data);
            }

            $user->syncRoles([$role->name]);

            if ($isStaff) {
                app(EmployeAffectationService::class)->definir($employe, $site, $fonction, $validateur);
            } else {
                // Pas de fiche Employe : seule la synchronisation de l'accès applicatif est
                // pertinente (remplace/confirme le site posé à l'acceptation de l'invitation).
                $user->sites()->sync([$site->id => ['role' => 'employe', 'is_default' => true]]);
            }

            $user->update(['status' => User::STATUS_ACTIVE, 'is_active' => true]);

            $this->auditLog->record($user, AuditEvent::VALIDATED, $validateur, null, [
                'role' => $role->name,
                'site_id' => $site->id,
                'is_staff' => $isStaff,
            ]);

            if ($user->email) {
                Mail::to($user->email)->send(new AccountValidatedMail($user));
            }
        });
    }

    /**
     * Rôles visibles : système ∪ organisation du compte (même règle que
     * RoleController::visibleRoles()) — jamais celui d'une autre organisation. `super_admin`
     * n'est attribuable que par un acteur déjà `super_admin` (jamais un mapping automatique
     * fonction → compte technique, jamais une élévation de privilège via cet écran).
     *
     * `$roleId` null = conserve le rôle déjà posé sur le compte (par l'invitation) — validation
     * "telle quelle", comportement historique.
     */
    private function resoudreRole(?int $roleId, User $user, ?string $orgId, User $validateur): Role
    {
        if ($roleId === null) {
            $currentRoleName = $user->getRoleNames()->first();
            if ($currentRoleName === null) {
                throw ValidationException::withMessages(['role_id' => 'Le profil d\'accès est obligatoire.']);
            }

            $role = Role::where('name', $currentRoleName)
                ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
                ->first();

            if ($role === null) {
                throw ValidationException::withMessages(['role_id' => 'Profil introuvable.']);
            }

            return $role;
        }

        // Cherche PAR ID SEUL d'abord (jamais scopé par organisation dans la requête elle-même) :
        // sinon un rôle d'une autre organisation ressort comme "introuvable" (422) au lieu du
        // 403 explicite attendu — même principe que RoleController::authorizeSameOrganization(),
        // qui résout d'abord via la route-model-binding, puis vérifie la frontière ensuite.
        $role = Role::find($roleId);

        if ($role === null) {
            throw ValidationException::withMessages(['role_id' => 'Profil introuvable.']);
        }

        if ($role->organization_id !== null && $role->organization_id !== $orgId) {
            abort(403, 'Ce profil appartient à une autre organisation.');
        }

        if ($role->name === 'super_admin' && ! $validateur->isSuperAdmin()) {
            abort(403, 'Seul un super_admin peut attribuer ce profil.');
        }

        return $role;
    }

    /**
     * `$siteId` null = conserve le site par défaut déjà posé sur le compte (par l'invitation).
     */
    private function resoudreSite(?string $siteId, User $user, ?string $orgId): Site
    {
        if ($siteId === null) {
            $site = $user->sites()->wherePivot('is_default', true)->first()
                ?? $user->sites()->first();

            if ($site === null) {
                throw ValidationException::withMessages(['site_id' => 'Le site est obligatoire.']);
            }

            return $site;
        }

        $site = Site::where('organization_id', $orgId)->find($siteId);
        if ($site === null) {
            throw ValidationException::withMessages(['site_id' => 'Site introuvable.']);
        }

        return $site;
    }

    /**
     * Réutilise un Employe déjà rattaché à la même Personne dans l'organisation (ex: créé côté RH
     * avant l'invitation) plutôt que de le dupliquer ou de bloquer la validation — le rapprochement
     * se fait par personne_id, déjà dédupliqué par téléphone à la création du compte (cf.
     * Personne::resoudreOuCreer() dans UserInvitationService::accept()), aucun nouvel appel de
     * résolution de doublon n'est nécessaire ici.
     */
    private function resoudreOuCreerEmploye(User $user, ?string $orgId, array $data): Employe
    {
        $employe = Employe::where('organization_id', $orgId)
            ->where('personne_id', $user->personne_id)
            ->first();

        if ($employe !== null) {
            $employe->update([
                'type_employe' => $data['type_employe'],
                'statut' => $data['statut'],
            ]);

            return $employe;
        }

        $matricule = app(MatriculeService::class)->generate($orgId, Employe::class);

        return Employe::create([
            'organization_id' => $orgId,
            'personne_id' => $user->personne_id,
            'matricule' => $matricule,
            'type_employe' => $data['type_employe'],
            'statut' => $data['statut'],
        ]);
    }
}
