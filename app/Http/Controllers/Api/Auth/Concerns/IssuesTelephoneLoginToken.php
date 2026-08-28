<?php

namespace App\Http\Controllers\Api\Auth\Concerns;

use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\User;

/**
 * Étapes communes à toute connexion réussie par téléphone, quel que soit le
 * moyen de preuve (mot de passe aujourd'hui — LoginController — ou OTP,
 * cf. rapport du 27/08/2026) : rattachement automatique livreur/propriétaire,
 * puis forme de la ressource `user` renvoyée au client. Extrait de
 * LoginController pour que la connexion par OTP réutilise EXACTEMENT le même
 * comportement post-authentification, sans dupliquer cette logique.
 */
trait IssuesTelephoneLoginToken
{
    /**
     * Si le téléphone de l'utilisateur correspond à un livreur ou propriétaire
     * sans user_id, on établit le lien automatiquement. L'attribution du rôle
     * Spatie correspondant est déléguée à BusinessProfileRoleObserver — d'où le
     * `->get()->each(->update())` plutôt qu'un update() de masse : seul un
     * update() par INSTANCE déclenche les events Eloquent que l'observer écoute.
     */
    private function lierCompteParTelephone(User $user): void
    {
        if (! $user->telephone) {
            return;
        }

        $normalise = Personne::normaliserTelephone($user->telephone);

        Livreur::whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
            ->whereNull('user_id')
            ->get()
            ->each(fn (Livreur $livreur) => $livreur->update(['user_id' => $user->id]));

        Proprietaire::whereHas('personne', fn ($q) => $q->where('telephone_normalise', $normalise))
            ->whereNull('user_id')
            ->get()
            ->each(fn (Proprietaire $proprietaire) => $proprietaire->update(['user_id' => $user->id]));
    }

    private function userResource(User $user): array
    {
        return [
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            'telephone' => $user->telephone,
            'email' => $user->email,
            // ->map(fn (string $r): string => $r) explicite (pas juste ->all()) : sans
            // ce recast typé, Scramble trace getRoleNames() jusqu'à la relation Eloquent
            // `roles` et documente `roles[]` comme une collection de modèles Role au lieu
            // du tableau de noms qu'il est vraiment (cf. audit OpenAPI du 27/08/2026).
            'roles' => $user->getRoleNames()->map(fn (string $r): string => $r)->values()->all(),
        ];
    }
}
