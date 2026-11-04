<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Client\ClientIdentityResolver;
use App\Services\Client\QrPayloadResolver;
use App\Services\Comptes\ActivitesCompteResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    #[Endpoint(
        description: '`roles` liste **tous** les rôles du compte (un compte peut cumuler un rôle '
            .'staff ET un rôle client/proprietaire/livreur, cf. décision multi-rôle du 26/08/2026) — '
            .'**tableau non ordonné**, ne jamais utiliser `roles[0]` comme "rôle principal" (bug de '
            .'conception déjà rencontré côté frontend). Les rôles ne décident jamais de l\'accès à '
            .'l\'espace client : tout compte actif y entre (compte unique, ADR 0055). Ce qui s\'affiche '
            .'vient de `context` (activités du compte) ; tous ses ids à `null` et `consultant_ids` vide = '
            .'compte sans activité, accueil neutre. `context` est résolu exclusivement via '
            .'`ClientIdentityResolver` à partir du compte authentifié, jamais un paramètre client. '
            .'`context.parrain_id` et `context.consultant_ids` suivent uniquement le rattachement posé au '
            .'back-office (ADR 0050).',
    )]
    public function __invoke(Request $request, ClientIdentityResolver $identityResolver, QrPayloadResolver $qrPayloadResolver, ActivitesCompteResolver $activites): JsonResponse
    {
        $user = $request->user();
        $identity = $identityResolver->resolve($user);
        $rattachements = $activites->resolve($user);

        return response()->json([
            'id' => $user->id,
            'prenom' => $user->prenom,
            'nom' => $user->nom,
            // Prénom + nom s'ils sont tous les deux connus, même si nom_complet contient une
            // désignation provisoire ("Chauffeur-1") ; sinon le nom complet de la fiche.
            'nom_affichage' => filled($user->prenom) && filled($user->nom)
                ? trim("{$user->prenom} {$user->nom}")
                : ($identity->proprietaire?->nom_affichage
                    ?: $identity->livreur?->nom_complet
                    ?: $identity->client?->nom_complet
                    ?: $user->personne?->nom_complet
                    ?: null),
            'telephone' => $user->telephone,
            'email' => $user->email,
            // Recast typé requis pour que Scramble documente roles[] comme un tableau de
            // noms — cf. commentaire identique dans LoginController::userResource().
            'roles' => $user->getRoleNames()->map(fn (string $r): string => $r)->values()->all(),
            'is_active' => $user->is_active,
            // Résolution centralisée dans QrPayloadResolver (cf. sa docblock) — avant
            // extraction, cette méthode cherchait le proprietaire/livreur par téléphone
            // SANS jamais tenter le user_id d'abord, et sans aucune restriction
            // d'organisation ni de "profil non réclamé" (cf. audit backend du 26/08/2026).
            'qr_payload' => $qrPayloadResolver->resolveForIdentity($identity),
            'context' => [
                'organization_id' => $identity->organizationId,
                'client_id' => $identity->client?->id,
                'proprietaire_id' => $identity->proprietaire?->id,
                'livreur_id' => $identity->livreur?->id,
                // Lu sur le rattachement posé au back-office (ADR 0050), jamais sur le téléphone.
                'parrain_id' => $rattachements->parrain?->id,
                // Consultants (prestataires) dont le compte perçoit les commissions, même rattachement
                // que le parrain (ADR 0050, 0054). Jamais `prestataire_id` : ce nom est réservé côté
                // frontend à un futur espace « prestations ».
                'consultant_ids' => $rattachements->consultants->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            ],
        ]);
    }
}
