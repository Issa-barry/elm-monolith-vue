<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $emailChanged = ($data['email'] ?? null) !== $user->email;

        $user->personne->update([
            'prenom' => $data['prenom'],
            'nom' => $data['nom'],
            'email' => $data['email'] ?? null,
        ]);

        $this->syncEmailIdentity($user, $data['email'] ?? null, $emailChanged);

        if (array_key_exists('telephone', $data) && filled($data['telephone'])) {
            $this->syncTelephoneIdentity($user, $data['telephone']);
        }

        return to_route('profile.edit');
    }

    /** Même pattern que UserController::syncEmailIdentity(), avec remise à zéro de la
     *  vérification si l'adresse a réellement changé. */
    private function syncEmailIdentity(User $user, ?string $email, bool $resetVerification): void
    {
        $identity = $user->emailIdentity();

        if (! $email) {
            $identity?->delete();

            return;
        }

        $normalized = UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $email);

        if ($identity) {
            $identity->update([
                'value' => $email,
                'normalized_value' => $normalized,
                'verified_at' => $resetVerification ? null : $identity->verified_at,
            ]);
        } else {
            $user->authIdentities()->create([
                'type' => UserAuthIdentity::TYPE_EMAIL,
                'value' => $email,
                'normalized_value' => $normalized,
                'verified_at' => null,
            ]);
        }
    }

    /**
     * Ce numéro n'est prouvé par aucun moyen ici (simple saisie dans un
     * formulaire de profil) — `verified_at` reste NULL, cf. rapport du
     * 27/08/2026 (règle de sécurité OTP, s'applique à toute vérification
     * d'identité téléphone, pas seulement au système OTP lui-même). Corrigé le
     * même jour : cette identité était auparavant marquée vérifiée à la simple
     * saisie, sans aucune preuve — déjà incohérent avec syncEmailIdentity()
     * ci-dessus, qui ne vérifie jamais un email à la simple présence du champ.
     */
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
                'is_primary' => true,
            ]);
        }
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
