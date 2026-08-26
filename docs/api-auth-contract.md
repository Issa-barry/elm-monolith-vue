# Contrat d'authentification API (Sanctum Bearer)

Socle commun à tous les clients API (mobile, futur BFF Nuxt, tout autre client
API) — `routes/web.php` (guard `web`, session, Fortify, Inertia) reste un système
séparé pour le staff et l'espace client Inertia historique et n'est pas concerné
par ce document.

## Endpoints

### `POST /api/auth/login`

```json
// Requête
{ "telephone": "+224620000100", "password": "...", "device_name": "elm-mobile-android" }
```

- `device_name` est obligatoire (max 255) — nomme le token créé (voir "Politique de
  tokens" ci-dessous). Convention suggérée : `elm-mobile-android`, `elm-mobile-ios`,
  `elm-nuxt-web`.
- Réponse `200` : `{ "token": "...", "user": {id, prenom, nom, telephone, email, roles} }`.
- Réponse `422` : identifiants invalides ou téléphone/mot de passe manquant (format
  Laravel standard `{message, errors}`).
- Réponse `403` : compte non éligible — voir "Statuts de compte" ci-dessous.
- Throttle : `10` tentatives / minute (clé IP).

### `GET /api/auth/me` (auth:sanctum)

```json
{
  "id": "...", "prenom": "...", "nom": "...", "telephone": "...", "email": "...",
  "roles": ["proprietaire"], "is_active": true,
  "qr_payload": "https://.../proprietaires/xxx" ,
  "context": {
    "organization_id": "...",
    "client_id": null,
    "proprietaire_id": "...",
    "livreur_id": null
  }
}
```

`context` est résolu via `App\Services\Client\ClientIdentityResolver` — jamais à
partir d'un paramètre envoyé par le client, toujours depuis l'utilisateur
authentifié. N'expose ni permissions détaillées, ni attributs Eloquent bruts.

### `GET /v1/mobile/profile` (auth:sanctum + rôle `client|proprietaire|livreur`)

Fiche complète du profil métier (adresse/pays/ville comprises) — distinct de
`/me` qui reste volontairement minimal. Le profil retourné dépend du profil
réellement rattaché au compte via `ClientIdentityResolver` (priorité
`proprietaire` > `client` > `livreur` si le compte en cumule plusieurs) —
jamais un paramètre choisi par le client :

```json
// Propriétaire personne physique
{
  "user": { "id": "...", "telephone": "+224622602693", "email": "..." },
  "profile": {
    "type": "proprietaire",
    "identite": { "prenom": "Moussa", "nom": "SIDIBÉ", "surnom": null, "nom_affichage": "Moussa SIDIBÉ" },
    "entreprise": null,
    "contact": { "telephone": "+224622602693", "email": "..." },
    "localisation": { "pays": "Guinée", "code_pays": "GN", "code_phone_pays": "+224", "ville": "Conakry", "adresse": "Matoto, Carrefour" },
    "actif": true,
    "notifications": { "activite": true }
  }
}
```

Pour un propriétaire entreprise : `entreprise: { "raison_sociale": "..." }`
(jamais un objet vide — `null` sinon). Pour un client/livreur : `profile.type`
vaut `"client"`/`"livreur"`, mêmes clés. `profile` vaut `null` si le compte
porte le rôle mais n'a en réalité aucune fiche Client/Proprietaire/Livreur
rattachée (cas limite, ne devrait pas arriver en usage normal).

**Pas de champ `siret`/identifiant légal** : notion absente du modèle ELM,
jamais exposée — ne pas en ajouter côté frontend sans un vrai champ backend
correspondant.

**Aucun champ "quartier" distinct en base** — seuls `pays`, `ville`, `adresse`
existent (sur `Personne` pour proprietaire/livreur, sur `Client` directement) ;
un quartier doit être saisi dans `adresse` en texte libre, ou une migration
serait nécessaire pour l'isoler.

### `PATCH /v1/mobile/profile` (auth:sanctum + rôle `client|proprietaire|livreur`)

Met à jour uniquement la **localisation** du profil rattaché au compte connecté :

```json
// Requête (tous les champs optionnels, seuls les envoyés sont modifiés)
{ "pays": "Guinée", "code_pays": "GN", "ville": "Kindia", "adresse": "Quartier Manquépas" }
```

Réponse : identique à `GET /v1/mobile/profile`. `404` si aucun profil n'est
rattaché au compte.

**Volontairement non modifiables par ce endpoint** : `nom`/`prenom`/`surnom`
(identité civile), `telephone`/`email` (identifiants de connexion, unicité par
organisation à revalider), `raison_sociale`/`type` (identité légale), `actif`
(jamais en self-service) — tout champ envoyé en plus de `pays`/`code_pays`/
`ville`/`adresse` est silencieusement ignoré par la validation. Ces champs
restent réservés au backoffice (`ProprietaireController`, `ClientController`).

### `PATCH /v1/mobile/profile/notification-preferences` (auth:sanctum + rôle `client|proprietaire|livreur`)

```json
// Requête
{ "preferences": { "activite": false } }
// Réponse
{ "notifications": { "activite": false } }
```

Persisté en base (`users.notification_preferences`, JSON) — jamais dans un
`localStorage` local à l'appareil : commun à Nuxt/PWA/mobile. Une catégorie
jamais réglée explicitement reste activée par défaut
(`User::NOTIFICATION_PREFERENCE_DEFAULTS`). Toute clé hors de cette liste est
silencieusement ignorée (liste blanche fermée — ajouter une catégorie future =
ajouter une entrée à la constante, sans migration).

Distinct de la permission système du navigateur/téléphone pour les push
(`Notification.requestPermission()`, gérée exclusivement côté frontend) et du
jeton technique `expo_push_token` (déjà existant, sert à *router* le push, pas
à décider si l'utilisateur en veut).

### `GET /v1/mobile/vehicules/mine` (auth:sanctum + rôle `client|proprietaire|livreur`)

Champ `conducteur` (string|null) ajouté le 26/08/2026 : nom du membre de
l'équipe ayant le rôle `chauffeur` sur ce véhicule (`null` si aucune équipe ou
aucun chauffeur assigné — jamais le premier membre pris au hasard, une équipe
peut n'avoir que des convoyeurs). **Aucun statut "en entretien"/maintenance
n'existe dans le modèle ELM** — seul `is_active` (booléen) existe ; un
frontend affichant un statut de ce type affiche une donnée fictive tant
qu'aucune colonne dédiée n'est ajoutée.

### `POST /api/auth/logout` (auth:sanctum)

Révoque uniquement le token courant (`currentAccessToken()->delete()`) — les
autres appareils/clients de l'utilisateur restent connectés.

### `POST /api/auth/logout-all` (auth:sanctum)

Révoque **tous** les tokens de l'utilisateur (`$user->tokens()->delete()`). À
utiliser depuis un écran "Sécurité" ou en cas de suspicion de compromission.

## Statuts de compte (403 au login, et sur toute route `auth:sanctum` ensuite)

| `code` | Signification | Se lève quand | Se résout par |
|---|---|---|---|
| `pending_validation` | En attente de validation par un admin | `status = pending_validation` | action admin (`validateAccount`) |
| `account_blocked` | Compte désactivé | `is_active = false`, hors les deux autres cas | réactivation admin |
| `email_not_verified` | Email non confirmé (auto-résolu) | `is_active = false` + `status = pending` (auto-inscription), OU `is_active = true` + email non vérifié | l'utilisateur clique le lien reçu par email |

Logique centralisée dans `App\Support\Auth\AccountEligibility` — réutilisée par
`LoginController` (API), `FortifyServiceProvider::authenticateUsing` (web) et
`EnsureApiAccountIsActive` (filet de sécurité API, voir plus bas), pour ne plus
dupliquer/diverger cette règle à chaque endroit.

**Important — filet de sécurité par requête** : `is_active` n'est pas vérifié
qu'au login. Le middleware global `App\Http\Middleware\EnsureApiAccountIsActive`
(appliqué à tout le groupe `api`, cf. `bootstrap/app.php`) coupe l'accès de tout
token dont le compte a été désactivé APRÈS son émission, sur toutes les routes
`auth:sanctum` (`/me`, `gains/mine`, `vehicules/mine`, etc.).

## Politique de tokens

- **Expiration** : `SANCTUM_EXPIRATION_MINUTES` (défaut 90 jours, cf.
  `config/sanctum.php`). Passé ce délai, le token est automatiquement invalide
  (401) sans action serveur nécessaire ; une purge quotidienne
  (`sanctum:prune-expired`, cf. `routes/console.php`) nettoie la table.
- **Multi-device** : chaque `device_name` obtient son propre token indépendant.
  Se connecter depuis un nouvel appareil ne déconnecte jamais les autres.
- **Logout** : révoque uniquement le token courant (voir ci-dessus).
- **Logout global** : `POST /api/auth/logout-all` révoque tous les tokens.
- **Changement de mot de passe volontaire** (`POST v1/mobile/auth/change-password`,
  utilisateur déjà connecté) : révoque tous les **autres** tokens, garde le
  courant. Protège contre un token compromis sans déconnecter l'appareil légitime
  qui vient de changer le mot de passe.
- **Réinitialisation après oubli** (`POST /api/auth/password/reset`, flux OTP) :
  révoque **tous** les tokens sans exception — l'utilisateur n'est authentifié
  nulle part au moment de la demande, il n'y a pas d'appareil "à préserver".

## Isolation multi-organisation / propriétaire / livreur

Tous les endpoints `Client\*` (véhicules, gains, commissions, frais, livraisons en
cours) résolvent l'organisation et le profil métier (Client/Proprietaire/Livreur)
exclusivement via `App\Services\Client\ClientIdentityResolver::resolve($request->user())`
— jamais depuis un paramètre de requête. Ce resolver centralise aussi la garde
contre le faux-positif par téléphone : un profil déjà réclamé par un autre compte
(`user_id` non null) ne peut jamais être apparié pour un second utilisateur par
simple coïncidence de numéro de téléphone.

Les routes `gains/mine`, `vehicules/mine`, `vehicules/{id}/commissions` et
`vehicules/{id}/frais` exigent en plus explicitement le rôle
`client|proprietaire|livreur` (`role:client|proprietaire|livreur`) — un token
`auth:sanctum` valide seul ne suffit pas.

## Prêt pour SPA directe ?

Ce chantier fiabilise l'auth Bearer existante ; il n'active **pas** le mode
Sanctum SPA cookie-session. Si ce mode est retenu plus tard (navigateur → Laravel
directement, sans BFF), il resterait à faire :

- Publier `config/cors.php` avec les origines explicites (jamais `*`), en activant
  `supports_credentials: true`.
- Ajouter `Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful` au
  groupe de middleware `web` (ou `api`, selon la stratégie retenue).
- Définir `SANCTUM_STATEFUL_DOMAINS` (domaines autorisés à recevoir le cookie de
  session Sanctum) et `SESSION_DOMAIN=.eau-la-maman.com` (cookie partagé sur le
  eTLD+1).
- Gérer côté client le cycle `GET /sanctum/csrf-cookie` → header `X-XSRF-TOKEN`
  sur toute requête mutative.
- Décider explicitement de l'usage `guard: ['web']` de `config/sanctum.php` selon
  que ce mode SPA doit interagir avec la session `web` existante ou rester
  indépendant.

Rien de ce qui précède n'est fait aujourd'hui — c'est une décision structurante
distincte, à prendre séparément (cf. audit du 26/08/2026, section E).
