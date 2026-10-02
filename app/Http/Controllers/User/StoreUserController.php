<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Personne;
use App\Models\User;
use App\Services\MatriculeService;
use App\Support\User\UserFormOptions;
use App\Support\User\UserIdentitySync;
use App\Support\User\UserPrivilegeGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        UserFormOptions::buildFullTelephone($request);

        $data = $request->validate([
            'prenom' => 'required|string|max:100',
            'nom' => 'required|string|max:100',
            'email' => 'nullable|email|max:255',
            'telephone' => 'required|string|max:50',
            'code_pays' => ['nullable', Rule::in(array_keys(UserFormOptions::PAYS))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:255',
            'role' => ['required', UserFormOptions::assignableRoleRule($orgId)],
            'site_id' => 'required|exists:sites,id',
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'is_active' => 'boolean',
        ], [
            'prenom.required' => 'Le prénom est obligatoire.',
            'nom.required' => 'Le nom est obligatoire.',
            'email.email' => "L'adresse e-mail est invalide.",
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'role.required' => 'Le rôle est obligatoire.',
            'role.exists' => 'Rôle invalide.',
            'site_id.required' => 'Le site est obligatoire.',
            'site_id.exists' => 'Site invalide.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);

        UserIdentitySync::assertIdentityUnique($data['telephone'], $data['email'] ?? null);
        UserPrivilegeGuard::assertNoPrivilegeEscalation($data['role']);

        $personneFields = UserIdentitySync::buildPersonneFields($data);
        $personne = Personne::resoudreOuCreer($orgId, $personneFields);

        $user = User::create([
            'personne_id' => $personne->id,
            'password' => $data['password'],
            'organization_id' => $orgId,
            'is_active' => $data['is_active'] ?? true,
        ]);
        UserIdentitySync::syncTelephoneIdentity($user, $data['telephone']);
        UserIdentitySync::syncEmailIdentity($user, $data['email'] ?? null);

        $user->assignRole($data['role']);
        $user->sites()->attach($data['site_id'], ['role' => 'employe', 'is_default' => true]);

        app(MatriculeService::class)->assignForUser($user);

        return redirect()->route('users.edit', $user)
            ->with('success', "{$user->name} a été créé avec succès.");
    }
}
