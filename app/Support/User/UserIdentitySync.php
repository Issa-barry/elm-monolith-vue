<?php

namespace App\Support\User;

use App\Models\Personne;
use App\Models\User;
use App\Models\UserAuthIdentity;
use Illuminate\Validation\ValidationException;

/**
 * Synchronisation des identités de connexion (téléphone/email) d'un utilisateur géré depuis le
 * back-office, partagée par les contrôleurs `App\Http\Controllers\User\*` — extrait de l'ancien
 * `UserController`.
 */
final class UserIdentitySync
{
    /** Champs d'identité (Personne) — jamais écrits directement sur users. */
    public static function buildPersonneFields(array $data): array
    {
        $resolved = UserFormOptions::resolvePays($data['code_pays'] ?? null);

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

    /**
     * Crée/renseigne l'identité de connexion téléphone (toujours obligatoire).
     * Ce numéro n'est prouvé par aucun moyen ici (saisi par un admin dans le
     * formulaire de gestion des utilisateurs) — `verified_at` reste NULL, cf.
     * rapport du 27/08/2026 (règle de sécurité OTP, s'applique à toute
     * vérification d'identité téléphone). Corrigé le même jour.
     */
    public static function syncTelephoneIdentity(User $user, string $telephone): void
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

    /** Crée/renseigne/supprime l'identité de connexion email (facultative). */
    public static function syncEmailIdentity(User $user, ?string $email): void
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

    /** Remplace unique:users,telephone / unique:users,email — vivent désormais dans user_auth_identities. */
    public static function assertIdentityUnique(string $telephone, ?string $email, ?string $ignoreUserId = null): void
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
}
