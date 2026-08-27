<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Résout le profil métier (organisation + Client/Proprietaire/Livreur) rattaché à un
 * utilisateur authentifié. Centralise une logique auparavant dupliquée (avec un edge
 * case d'isolation organisation différent à chaque copie) dans VehiculesController,
 * GainsController, VehiculeCommissionsController, VehiculeFraisController,
 * LivraisonsEnCoursController, ClientDashboardController et MeController.
 *
 * Garde de sécurité : le rattachement par téléphone (repli utilisé quand
 * `organization_id` n'est pas encore connu sur le User, typiquement juste après une
 * inscription qui n'est pas passée par LoginController::lierCompteParTelephone()) ne
 * matche jamais qu'un profil NON réclamé (`user_id` null) — même garde que
 * LoginController::lierCompteParTelephone(). Sans ce garde-fou, un profil déjà lié à
 * un AUTRE compte (potentiellement d'une autre organisation, ou même le compte d'une
 * tierce personne partageant la même chaîne de téléphone brute) pouvait être apparié
 * par simple coïncidence de téléphone dès que `$user->organization_id` était encore
 * null — cf. audit backend du 26/08/2026. `MeController::resolveQrPayload()` avait la
 * même faille en pire : le repli téléphone y était utilisé SANS jamais tenter le
 * `user_id` d'abord, et sans aucune restriction d'organisation.
 */
class ClientIdentityResolver
{
    public function resolve(User $user): ClientIdentity
    {
        $organizationId = $user->organization_id;
        $telephone = $user->telephone;

        $client = $this->match(
            Client::query(),
            $user,
            $telephone,
            $organizationId,
            fn (Builder $q, string $tel) => $q->where('telephone', $tel),
        );
        if ($organizationId === null && $client !== null) {
            $organizationId = $client->organization_id;
        }

        $proprietaire = $this->match(
            Proprietaire::query(),
            $user,
            $telephone,
            $organizationId,
            fn (Builder $q, string $tel) => $q->whereHas('personne', fn ($p) => $p->where('telephone', $tel)),
        );
        if ($organizationId === null && $proprietaire !== null) {
            $organizationId = $proprietaire->organization_id;
        }

        $livreur = $this->match(
            Livreur::query(),
            $user,
            $telephone,
            $organizationId,
            fn (Builder $q, string $tel) => $q->whereHas('personne', fn ($p) => $p->where('telephone', $tel)),
        );
        if ($organizationId === null && $livreur !== null) {
            $organizationId = $livreur->organization_id;
        }

        // Une fois l'organisation connue (éventuellement déduite d'un des profils
        // ci-dessus), un profil résolu AVANT qu'elle ne le soit et qui se trouve être
        // dans une autre organisation ne doit jamais rester rattaché.
        if ($organizationId !== null) {
            if ($client !== null && $client->organization_id !== $organizationId) {
                $client = null;
            }
            if ($proprietaire !== null && $proprietaire->organization_id !== $organizationId) {
                $proprietaire = null;
            }
            if ($livreur !== null && $livreur->organization_id !== $organizationId) {
                $livreur = null;
            }
        }

        return new ClientIdentity($organizationId, $client, $proprietaire, $livreur);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(Builder<TModel>, string): void  $matchTelephone
     * @return TModel|null
     */
    private function match(
        Builder $query,
        User $user,
        ?string $telephone,
        ?string $organizationId,
        Closure $matchTelephone,
    ): ?Model {
        return $query
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->where(function (Builder $q) use ($user, $telephone, $matchTelephone) {
                $q->where('user_id', $user->id);

                if ($telephone) {
                    $q->orWhere(function (Builder $sub) use ($telephone, $matchTelephone) {
                        $matchTelephone($sub, $telephone);
                        // Un profil déjà réclamé par un autre compte ne doit jamais être
                        // apparié via le seul téléphone — cf. commentaire de classe.
                        $sub->whereNull('user_id');
                    });
                }
            })
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->first();
    }
}
