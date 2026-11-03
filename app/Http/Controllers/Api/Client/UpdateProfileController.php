<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Client\UpdateProfileRequest;
use App\Http\Resources\Api\Client\ProfileResource;
use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Services\Client\ChangementEmailProfilService;
use App\Services\Client\ClientIdentityResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Met à jour le profil de l'utilisateur connecté (ADR 0051) : prénom/nom sur chaque
 * Personne du compte et sur Client, localisation sur le profil affiché. L'e-mail passe
 * par ChangementEmailProfilService : confirmation par lien, jamais d'écriture directe.
 */
class UpdateProfileController extends Controller
{
    #[Endpoint(
        description: 'Tous les champs sont optionnels, seuls les champs envoyés sont modifiés. '
            .'`prenom`/`nom` : appliqués immédiatement, ne peuvent pas être vidés. `email` : '
            .'appliqué seulement à l\'ouverture du lien envoyé à la nouvelle adresse (valable '
            .'24 h) ; `email_confirmation` indique l\'adresse concernée et si l\'envoi a réussi, '
            .'`null` sans demande. `pays` : un libellé de `pays_disponibles`. **Non modifiables** '
            .'(silencieusement ignorés si envoyés) : `telephone`, `code_pays`, `code_phone_pays`, '
            .'`surnom`, `raison_sociale`, `type`, `actif`. `404` si la localisation est envoyée '
            .'sans fiche métier rattachée.',
    )]
    public function __invoke(
        UpdateProfileRequest $request,
        ClientIdentityResolver $identityResolver,
        ChangementEmailProfilService $changementEmail,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $identity = $identityResolver->resolve($user);

        $data = $request->validated();
        $identite = Arr::only($data, ['prenom', 'nom']);
        $localisation = Arr::only($data, ['pays', 'ville', 'adresse']);
        $email = $data['email'] ?? null;

        $resource = $identity->proprietaire ?? $identity->client ?? $identity->livreur;

        $target = match (true) {
            $resource instanceof Proprietaire => $resource->personne,
            $resource instanceof Client => $resource,
            $resource instanceof Livreur => $resource->personne,
            default => null,
        };

        $compteSeulement = $localisation === [] && ($identite !== [] || $email !== null);

        if ($target === null && ! $compteSeulement) {
            return response()->json(['message' => 'Aucun profil rattaché à ce compte.'], 404);
        }

        $emailConfirmation = null;
        if ($email !== null && $email !== mb_strtolower((string) $user->email, 'UTF-8')) {
            $emailConfirmation = [
                'adresse' => $email,
                'envoyee' => $changementEmail->demander($user, $identity, $email),
            ];
        }

        DB::transaction(function () use ($user, $identity, $identite, $localisation, $resource, $target) {
            if ($identite !== []) {
                $identity->personnesDu($user)->each->update($identite);
                $identity->client?->update($identite);
            }

            if ($localisation !== [] && $target !== null) {
                $target->update($localisation);

                // Client garde ses propres colonnes (source des affichages back-office existants),
                // mais reste un rôle porté par Personne (cf. docs/identite-client-personne.md) : sans
                // ce miroir, un client qui modifie sa localisation ici resterait à jour côté Client
                // mais figé côté Personne, visible depuis un autre rôle (Parrain, Propriétaire...) de
                // la même personne physique. Silencieux si personne_id n'est pas encore renseigné
                // (ne doit plus arriver après le backfill, mais jamais bloquant ici).
                if ($resource instanceof Client) {
                    $resource->personne?->update($localisation);
                }
            }
        });

        $reponse = (new ProfileResource($resource?->fresh(), $user))->resolve($request);
        $reponse['email_confirmation'] = $emailConfirmation;

        return response()->json($reponse);
    }
}
