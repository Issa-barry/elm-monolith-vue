<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\StatutEmploye;
use App\Enums\TypeEmploye;
use App\Models\FonctionRh;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\AuditLogService;
use App\Services\MatriculeService;
use App\Services\Rh\AccountValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

const USER_PAYS = [
    'GN' => ['Guinée',               '+224'],
    'GW' => ['Guinée-Bissau',        '+245'],
    'SN' => ['Sénégal',              '+221'],
    'ML' => ['Mali',                 '+223'],
    'CI' => ["Côte d'Ivoire",        '+225'],
    'LR' => ['Liberia',              '+231'],
    'SL' => ['Sierra Leone',         '+232'],
    'FR' => ['France',               '+33'],
    'CN' => ['Chine',                '+86'],
    'AE' => ['Émirats arabes unis',  '+971'],
    'IN' => ['Inde',                 '+91'],
];

class UserController extends Controller
{
    public const STAFF_ROLES = ['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable'];

    /**
     * Rôles pouvant être attribués via une invitation. Les rôles admin sont
     * volontairement exclus : ils ne peuvent être attribués qu'après validation
     * du compte, depuis la gestion utilisateur (voir update()).
     */
    public const INVITABLE_ROLES = ['manager', 'commerciale', 'comptable'];

    public const ADMIN_ROLES = ['super_admin', 'admin_entreprise'];

    private function getRoleOptions(): Collection
    {
        return Role::whereIn('name', self::STAFF_ROLES)
            ->get(['id', 'name'])
            ->map(fn ($r) => ['value' => $r->name, 'label' => $r->name]);
    }

    private function getSiteOptions(string $orgId): Collection
    {
        return Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->nom} ({$s->code})"]);
    }

    /**
     * Profils d'accès proposables à la VALIDATION de compte (écran distinct de Users/Create-Edit,
     * qui reste sur STAFF_ROLES ci-dessus, hors périmètre) — tous les rôles visibles de
     * l'organisation (système ∪ org, même règle que RoleController::visibleRoles()), jamais ceux
     * d'une autre organisation. `super_admin` n'apparaît que si l'acteur l'est déjà lui-même
     * (jamais un mapping automatique, jamais une élévation de privilège via cet écran).
     */
    private function validationRoleOptions(?string $orgId, bool $actorIsSuperAdmin): Collection
    {
        return Role::where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
            ->when(! $actorIsSuperAdmin, fn ($q) => $q->where('name', '!=', 'super_admin'))
            ->orderBy('label')
            ->get(['id', 'label', 'name'])
            ->map(fn (Role $r) => ['value' => $r->id, 'label' => $r->label ?? $r->name])
            ->values();
    }

    private function resolvePays(?string $codePays): array
    {
        if ($codePays && isset(USER_PAYS[$codePays])) {
            [$pays, $codePhonePays] = USER_PAYS[$codePays];

            return ['pays' => $pays, 'code_phone_pays' => $codePhonePays];
        }

        return ['pays' => null, 'code_phone_pays' => null];
    }

    /** Champs d'identité (Personne) — jamais écrits directement sur users. */
    private function buildPersonneFields(array $data): array
    {
        $resolved = $this->resolvePays($data['code_pays'] ?? null);

        return [
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'telephone' => $data['telephone'],
            'telephone_normalise' => Personne::normaliserTelephone($data['telephone']),
            'pays' => $resolved['pays'],
            'code_pays' => $data['code_pays'] ?? null,
            'code_phone_pays' => $resolved['code_phone_pays'],
            'ville' => isset($data['ville']) ? mb_convert_case(mb_strtolower($data['ville'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
            'adresse' => isset($data['adresse']) ? mb_convert_case(mb_strtolower($data['adresse'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
            'email' => isset($data['email']) ? mb_strtolower($data['email'], 'UTF-8') : null,
        ];
    }

    /** Crée/renseigne l'identité de connexion téléphone (toujours obligatoire). */
    private function syncTelephoneIdentity(User $user, string $telephone): void
    {
        $identity = $user->telephoneIdentity();
        $normalized = Personne::normaliserTelephone($telephone);

        if ($identity) {
            $identity->update(['value' => $telephone, 'normalized_value' => $normalized]);
        } else {
            $user->authIdentities()->create([
                'type' => UserAuthIdentity::TYPE_TELEPHONE,
                'value' => $telephone,
                'normalized_value' => $normalized,
                'verified_at' => now(),
                'is_primary' => true,
            ]);
        }
    }

    /** Crée/renseigne/supprime l'identité de connexion email (facultative). */
    private function syncEmailIdentity(User $user, ?string $email): void
    {
        $identity = $user->emailIdentity();

        if (! $email) {
            $identity?->delete();

            return;
        }

        $normalized = UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $email);

        if ($identity) {
            $identity->update(['value' => $email, 'normalized_value' => $normalized]);
        } else {
            $user->authIdentities()->create([
                'type' => UserAuthIdentity::TYPE_EMAIL,
                'value' => $email,
                'normalized_value' => $normalized,
                'verified_at' => now(),
            ]);
        }
    }

    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $authUser = auth()->user();
        $orgId = $authUser->organization_id;

        $users = User::with([
            'personne', 'authIdentities', 'roles:id,name',
            'sites' => fn ($q) => $q->wherePivot('is_default', true)->select('sites.id', 'sites.nom', 'sites.code')->limit(1),
        ])
            ->where('organization_id', $orgId)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
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
                    'code_phone_pays' => ($u->code_pays && isset(USER_PAYS[$u->code_pays]))
                        ? USER_PAYS[$u->code_pays][1]
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

        return Inertia::render('Users/Index', [
            'users' => $users,
            'pending_registrations' => $pendingRegistrations,
            // Options du modal de validation de compte (ValidateAccountModal.vue) — cf.
            // AccountValidationService. Vides si l'organisation n'a pas encore de site/fonction ;
            // le front affiche alors un état invitant à en créer un avant de pouvoir valider un
            // membre du personnel.
            'validation_role_options' => $orgId ? $this->validationRoleOptions($orgId, $authUser->isSuperAdmin()) : [],
            'validation_site_options' => $orgId ? $this->getSiteOptions($orgId) : [],
            'validation_fonction_options' => $orgId
                ? FonctionRh::where('organization_id', $orgId)->where('is_active', true)->orderBy('libelle')
                    ->get(['id', 'libelle'])->map(fn (FonctionRh $f) => ['value' => $f->id, 'label' => $f->libelle])
                : [],
            'type_employe_options' => TypeEmploye::options(),
            'statut_employe_options' => StatutEmploye::options(),
            // Remplace les dictionnaires ROLE_LABELS figés côté Vue : un rôle personnalisé
            // d'organisation (créé via RoleController) a désormais un label lisible dans la
            // colonne "Rôle" de cette liste, pas seulement les 5 rôles historiques.
            'role_labels' => $orgId
                ? Role::where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))
                    ->pluck('label', 'name')
                : [],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        $orgId = auth()->user()->organization_id;

        return Inertia::render('Users/Create', [
            'roles' => $this->getRoleOptions(),
            'sites' => $this->getSiteOptions($orgId),
        ]);
    }

    private function buildFullTelephone(Request $request): void
    {
        $codePays = $request->input('code_pays');
        $local = (string) $request->input('telephone', '');

        if ($codePays && isset(USER_PAYS[$codePays]) && $local !== '') {
            [, $dial] = USER_PAYS[$codePays];
            $dialDigits = preg_replace('/\D+/', '', $dial) ?? '';
            $localDigits = preg_replace('/\D+/', '', $local) ?? '';
            $localDigits = preg_replace('/^0/', '', $localDigits);
            $request->merge(['telephone' => '+'.$dialDigits.$localDigits]);
        }
    }

    /**
     * Élévation de privilège corrigée le 2026-08-21 : `role` n'était validé que contre
     * STAFF_ROLES (qui inclut `super_admin`), sans jamais vérifier que l'ACTEUR l'est lui-même —
     * n'importe quel utilisateur autorisé à modifier des comptes pouvait donc s'attribuer ou
     * attribuer `super_admin` à un tiers. `admin_entreprise` reste attribuable par tout
     * `admin_entreprise` (cohérent avec RoleController::canManageRoles()) — seul `super_admin`
     * est concerné, mirroring exact de la garde AccountValidationService::resoudreRole().
     */
    private function assertNoPrivilegeEscalation(string $role): void
    {
        if ($role === 'super_admin' && ! auth()->user()->isSuperAdmin()) {
            abort(403, 'Seul un super_admin peut attribuer ce rôle.');
        }
    }

    /** Remplace unique:users,telephone / unique:users,email — vivent désormais dans user_auth_identities. */
    private function assertIdentityUnique(string $telephone, ?string $email, ?string $ignoreUserId = null): void
    {
        $telephoneUser = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($telephone));
        if ($telephoneUser && $telephoneUser->id !== $ignoreUserId) {
            throw ValidationException::withMessages(['telephone' => 'Ce numéro de téléphone est déjà utilisé.']);
        }

        if ($email) {
            $emailUser = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_EMAIL, UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $email));
            if ($emailUser && $emailUser->id !== $ignoreUserId) {
                throw ValidationException::withMessages(['email' => 'Cette adresse e-mail est déjà utilisée.']);
            }
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $this->buildFullTelephone($request);

        $data = $request->validate([
            'prenom' => 'required|string|max:100',
            'nom' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
            'telephone' => 'required|string|max:50',
            'code_pays' => ['nullable', Rule::in(array_keys(USER_PAYS))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:255',
            'role' => ['required', Rule::in(self::STAFF_ROLES)],
            'site_id' => 'required|exists:sites,id',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'is_active' => 'boolean',
        ], [
            'prenom.required' => 'Le prénom est obligatoire.',
            'nom.required' => 'Le nom est obligatoire.',
            'email.email' => "L'adresse e-mail est invalide.",
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => 'Rôle invalide.',
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Site invalide.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);

        $this->assertIdentityUnique($data['telephone'], $data['email'] ?? null);
        $this->assertNoPrivilegeEscalation($data['role']);

        $personneFields = $this->buildPersonneFields($data);
        $personne = Personne::resoudreOuCreer($orgId, $personneFields);

        $user = User::create([
            'personne_id' => $personne->id,
            'password' => $data['password'],
            'organization_id' => $orgId,
            'is_active' => $data['is_active'] ?? true,
        ]);
        $this->syncTelephoneIdentity($user, $data['telephone']);
        $this->syncEmailIdentity($user, $data['email'] ?? null);

        $user->assignRole($data['role']);
        $user->sites()->attach($data['site_id'], ['role' => 'employe', 'is_default' => true]);

        app(MatriculeService::class)->assignForUser($user);

        return redirect()->route('users.edit', $user)
            ->with('success', "{$user->name} a été créé avec succès.");
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        $orgId = auth()->user()->organization_id;
        $defaultSite = $user->sites()->wherePivot('is_default', true)->first();

        return Inertia::render('Users/Edit', [
            'user' => [
                'id' => $user->id,
                'prenom' => $user->prenom,
                'nom' => $user->nom,
                'email' => $user->email,
                'telephone' => $user->telephone,
                'code_pays' => $user->code_pays,
                'ville' => $user->ville,
                'adresse' => $user->adresse,
                'role' => $user->getRoleNames()->first(),
                'site_id' => $defaultSite?->id,
                'is_active' => $user->is_active,
                'matricule' => $user->matricule,
            ],
            'roles' => $this->getRoleOptions(),
            'sites' => $this->getSiteOptions($orgId),
            'is_me' => $user->id === auth()->id(),
        ]);
    }

    public function update(Request $request, User $user, AuditLogService $auditLog): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->buildFullTelephone($request);

        $data = $request->validate([
            'prenom' => 'required|string|max:100',
            'nom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['required', 'string', 'max:50'],
            'code_pays' => ['nullable', Rule::in(array_keys(USER_PAYS))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:255',
            'role' => ['required', Rule::in(self::STAFF_ROLES)],
            'site_id' => 'required|exists:sites,id',
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
            'is_active' => 'boolean',
        ], [
            'prenom.required' => 'Le prénom est obligatoire.',
            'nom.required' => 'Le nom est obligatoire.',
            'email.email' => "L'adresse e-mail est invalide.",
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'role.required' => 'Le rôle est obligatoire.',
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Site invalide.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        $this->assertIdentityUnique($data['telephone'], $data['email'] ?? null, $user->id);
        $this->assertNoPrivilegeEscalation($data['role']);

        // Un rôle admin ne peut être attribué qu'à un compte déjà validé : un compte
        // en attente doit d'abord être validé avant toute élévation de privilèges.
        if (in_array($data['role'], self::ADMIN_ROLES, true) && $user->isPendingValidation()) {
            throw ValidationException::withMessages([
                'role' => "Ce compte doit d'abord être validé avant de pouvoir lui attribuer un rôle administrateur.",
            ]);
        }

        $previousRole = $user->getRoleNames()->first();

        $personneFields = $this->buildPersonneFields($data);
        $user->personne->update(collect($personneFields)->except(['telephone', 'telephone_normalise'])->all());
        $this->syncTelephoneIdentity($user, $data['telephone']);
        $this->syncEmailIdentity($user, $data['email'] ?? null);

        $userUpdate = ['is_active' => $data['is_active'] ?? $user->is_active];
        if (! empty($data['password'])) {
            $userUpdate['password'] = $data['password'];
        }
        $user->update($userUpdate);
        $user->syncRoles([$data['role']]);

        if ($previousRole !== $data['role']) {
            $auditLog->record($user, AuditEvent::UPDATED, auth()->user(), ['role' => $previousRole], ['role' => $data['role']]);
        }

        if (! empty($data['site_id'])) {
            $user->sites()->sync([$data['site_id'] => ['role' => 'employe', 'is_default' => true]]);
        }

        return redirect()->route('users.edit', $user)
            ->with('success', "{$user->name} a été mis à jour.");
    }

    /**
     * PATCH /users/{user}/validate
     * Valide un compte en attente : choix du profil d'accès, du site, et — pour un membre du
     * personnel — de la fonction RH (avec création ou rattachement de la fiche Employe). Toute la
     * logique transactionnelle vit dans AccountValidationService (cf. plan "fonctions RH/profils
     * d'accès/sites") — ce contrôleur ne fait que valider la requête et déléguer.
     */
    public function validateAccount(Request $request, User $user, AccountValidationService $service): RedirectResponse
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

    /**
     * PATCH /users/{user}/reject
     * Refuse un compte en attente : il reste bloqué (inactif).
     */
    public function rejectAccount(User $user, AuditLogService $auditLog): RedirectResponse
    {
        $this->authorize('update', $user);

        abort_unless($user->isPendingValidation(), 422, "Ce compte n'est pas en attente de validation.");

        $user->update([
            'status' => User::STATUS_INACTIVE,
            'is_active' => false,
        ]);

        $auditLog->record($user, AuditEvent::REJECTED, auth()->user());

        return back()->with('success', "{$user->name} a été refusé.");
    }

    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);

        $user->update(['password' => $data['password']]);

        return redirect()->route('users.edit', $user)
            ->with('success', "Mot de passe de {$user->name} mis à jour.");
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        abort_if(auth()->id() === $user->id, 403, 'Vous ne pouvez pas supprimer votre propre compte.');

        $name = $user->name;
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', "{$name} a été supprimé.");
    }
}
