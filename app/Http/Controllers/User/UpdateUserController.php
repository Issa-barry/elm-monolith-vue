<?php

namespace App\Http\Controllers\User;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\User\UserFormOptions;
use App\Support\User\UserIdentitySync;
use App\Support\User\UserPrivilegeGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UpdateUserController extends Controller
{
    public function __invoke(Request $request, User $user, AuditLogService $auditLog): RedirectResponse
    {
        $this->authorize('update', $user);

        UserFormOptions::buildFullTelephone($request);

        $data = $request->validate([
            'prenom' => 'required|string|max:100',
            'nom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['required', 'string', 'max:50'],
            'code_pays' => ['nullable', Rule::in(array_keys(UserFormOptions::PAYS))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:255',
            // Scopé à l'organisation du compte CIBLE (pas celle de l'acteur), même raison que
            // site_id ci-dessous.
            'role' => ['required', UserFormOptions::assignableRoleRule($user->organization_id)],
            // Scopé à l'organisation du compte CIBLE (pas celle de l'acteur) : un super_admin
            // peut désormais modifier un agent depuis la console plateforme /backoffice/comptes,
            // qui liste des agents de toutes les organisations — sans ce scope, un site
            // d'une autre organisation resterait accepté par `exists:sites,id` seul.
            'site_id' => ['required', Rule::exists('sites', 'id')->where('organization_id', $user->organization_id)],
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
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
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        UserIdentitySync::assertIdentityUnique($data['telephone'], $data['email'] ?? null, $user->id);
        UserPrivilegeGuard::assertNoPrivilegeEscalation($data['role']);

        // Un rôle admin ne peut être attribué qu'à un compte déjà validé : un compte
        // en attente doit d'abord être validé avant toute élévation de privilèges.
        if (in_array($data['role'], UserFormOptions::ADMIN_ROLES, true) && $user->isPendingValidation()) {
            throw ValidationException::withMessages([
                'role' => "Ce compte doit d'abord être validé avant de pouvoir lui attribuer un rôle administrateur.",
            ]);
        }

        $previousRole = $user->getRoleNames()->first();

        $personneFields = UserIdentitySync::buildPersonneFields($data);
        $user->personne->update(collect($personneFields)->except(['telephone', 'telephone_normalise'])->all());
        UserIdentitySync::syncTelephoneIdentity($user, $data['telephone']);
        UserIdentitySync::syncEmailIdentity($user, $data['email'] ?? null);

        $userUpdate = ['is_active' => $data['is_active'] ?? $user->is_active];
        if (! empty($data['password'])) {
            $userUpdate['password'] = $data['password'];
        }
        $user->update($userUpdate);

        // syncRoles() remplace TOUS les rôles — un compte qui cumule ce rôle staff
        // avec un rôle client/proprietaire/livreur (ex: un admin qui possède aussi
        // un véhicule, cf. décision du 26/08/2026) perdrait silencieusement ce rôle
        // externe si on ne le préservait pas explicitement ici. Ce formulaire ne
        // gère que le rôle staff — jamais le rôle externe, qui vient exclusivement
        // du rattachement à un profil Client/Proprietaire/Livreur.
        $externalRoles = $user->getRoleNames()->intersect(User::EXTERNAL_ROLES)->all();
        $user->syncRoles([$data['role'], ...$externalRoles]);

        if ($previousRole !== $data['role']) {
            $auditLog->record($user, AuditEvent::UPDATED, auth()->user(), ['role' => $previousRole], ['role' => $data['role']]);
        }

        if (! empty($data['site_id'])) {
            $user->sites()->sync([$data['site_id'] => ['role' => 'employe', 'is_default' => true]]);
        }

        return redirect()->route('users.edit', $user)
            ->with('success', "{$user->name} a été mis à jour.");
    }
}
