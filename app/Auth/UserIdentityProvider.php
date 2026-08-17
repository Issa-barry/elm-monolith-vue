<?php

namespace App\Auth;

use App\Models\Personne;
use App\Models\UserAuthIdentity;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * `retrieveByCredentials()` par défaut construit `WHERE <clé> = <valeur>` directement sur la
 * table `users` — or `email`/`telephone` ne sont plus des colonnes de `users` depuis la refonte
 * PERSONNE + USERS (elles vivent dans `user_auth_identities`). Sans ce provider, le password
 * broker Laravel (mot de passe oublié) ne retrouve jamais aucun utilisateur.
 */
class UserIdentityProvider extends EloquentUserProvider
{
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (isset($credentials['email'])) {
            return UserAuthIdentity::resoudre(
                UserAuthIdentity::TYPE_EMAIL,
                UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, $credentials['email'])
            );
        }

        if (isset($credentials['telephone'])) {
            return UserAuthIdentity::resoudre(
                UserAuthIdentity::TYPE_TELEPHONE,
                Personne::normaliserTelephone($credentials['telephone'])
            );
        }

        return parent::retrieveByCredentials($credentials);
    }
}
