<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
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
    private const STAFF_ROLES = ['super_admin', 'admin_entreprise', 'manager', 'commerciale', 'comptable'];

    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        $orgId = auth()->user()->organization_id;

        $users = User::with([
                'roles:id,name',
                'sites' => fn ($q) => $q->wherePivot('is_default', true)->select('sites.id', 'sites.nom', 'sites.code')->limit(1),
            ])
            ->where('organization_id', $orgId)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::STAFF_ROLES))
            ->orderBy('nom')
            ->get()
            ->map(function (User $u) {
                $defaultSite = $u->sites->first();
                return [
                    'id'             => $u->id,
                    'nom'            => $u->nom,
                    'prenom'         => $u->prenom,
                    'nom_complet'    => $u->name,
                    'email'          => $u->email,
                    'telephone'      => $u->telephone,
                    'code_phone_pays' => ($u->code_pays && isset(USER_PAYS[$u->code_pays]))
                        ? USER_PAYS[$u->code_pays][1]
                        : null,
                    'is_active'      => $u->is_active,
                    'roles'          => $u->getRoleNames(),
                    'site'           => $defaultSite ? "{$defaultSite->nom} ({$defaultSite->code})" : null,
                    'is_me'          => $u->id === auth()->id(),
                ];
            });

        return Inertia::render('Users/Index', [
            'users' => $users,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        $orgId = auth()->user()->organization_id;

        $roles = Role::whereIn('name', self::STAFF_ROLES)
            ->get(['id', 'name'])
            ->map(fn ($r) => ['value' => $r->name, 'label' => $r->name]);

        $sites = \App\Models\Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->nom} ({$s->code})"]);

        return Inertia::render('Users/Create', [
            'roles' => $roles,
            'sites' => $sites,
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

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $this->buildFullTelephone($request);

        $data = $request->validate([
            'prenom' => 'required|string|max:100',
            'nom' => 'required|string|max:100',
            'email' => 'nullable|email|max:255|unique:users,email',
            'telephone' => 'required|string|max:50|unique:users,telephone',
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
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => 'Rôle invalide.',
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Site invalide.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);

        $pays = null;
        $codePhonePays = null;
        $codePays = $data['code_pays'] ?? null;

        if ($codePays && isset(USER_PAYS[$codePays])) {
            [$pays, $codePhonePays] = USER_PAYS[$codePays];
        }

        $user = User::create([
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'email' => isset($data['email']) ? mb_strtolower($data['email'], 'UTF-8') : null,
            'telephone' => $data['telephone'],
            'pays' => $pays,
            'code_pays' => $codePays,
            'code_phone_pays' => $codePhonePays,
            'ville' => isset($data['ville']) ? mb_convert_case(mb_strtolower($data['ville'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
            'adresse' => isset($data['adresse']) ? mb_convert_case(mb_strtolower($data['adresse'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
            'is_active' => $data['is_active'] ?? true,
            'password' => Hash::make($data['password']),
            'organization_id' => $orgId,
        ]);

        $user->assignRole($data['role']);
        $user->sites()->attach($data['site_id'], ['role' => 'employe', 'is_default' => true]);

        return redirect()->route('users.edit', $user)
            ->with('success', "{$user->name} a été créé avec succès.");
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        $orgId = auth()->user()->organization_id;

        $roles = Role::whereIn('name', self::STAFF_ROLES)
            ->get(['id', 'name'])
            ->map(fn ($r) => ['value' => $r->name, 'label' => $r->name]);

        $sites = \App\Models\Site::where('organization_id', $orgId)
            ->orderBy('nom')
            ->get(['id', 'nom', 'code'])
            ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->nom} ({$s->code})"]);

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
            ],
            'roles' => $roles,
            'sites' => $sites,
            'is_me' => $user->id === auth()->id(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $this->buildFullTelephone($request);

        $data = $request->validate([
            'prenom' => 'required|string|max:100',
            'nom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'telephone' => ['required', 'string', 'max:50', Rule::unique('users', 'telephone')->ignore($user->id)],
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
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'role.required' => 'Le rôle est obligatoire.',
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Site invalide.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        $pays = null;
        $codePhonePays = null;
        $codePays = $data['code_pays'] ?? null;

        if ($codePays && isset(USER_PAYS[$codePays])) {
            [$pays, $codePhonePays] = USER_PAYS[$codePays];
        }

        $updateData = [
            'prenom' => mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8'),
            'nom' => mb_strtoupper($data['nom'], 'UTF-8'),
            'email' => isset($data['email']) ? mb_strtolower($data['email'], 'UTF-8') : null,
            'telephone' => $data['telephone'],
            'pays' => $pays,
            'code_pays' => $codePays,
            'code_phone_pays' => $codePhonePays,
            'ville' => isset($data['ville']) ? mb_convert_case(mb_strtolower($data['ville'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
            'adresse' => isset($data['adresse']) ? mb_convert_case(mb_strtolower($data['adresse'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : null,
            'is_active' => $data['is_active'] ?? $user->is_active,
        ];

        if (! empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        $user->update($updateData);
        $user->syncRoles([$data['role']]);

        if (!empty($data['site_id'])) {
            $user->sites()->sync([$data['site_id'] => ['role' => 'employe', 'is_default' => true]]);
        }

        return redirect()->route('users.edit', $user)
            ->with('success', "{$user->name} a été mis à jour.");
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

        $user->update(['password' => Hash::make($data['password'])]);

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
