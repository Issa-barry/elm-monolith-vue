# Contrat API — Espace client (Nuxt / mobile)

Documentation complète, vérifiée directement dans le code de `elm-monolithe`
(pas de suppositions), de tout ce qu'un frontend "espace client" (Nuxt, PWA,
app mobile) peut consommer aujourd'hui. Couvre l'authentification, le profil,
les véhicules, les gains, les dépenses, les livraisons, les notifications et la
recherche — avec, pour chaque section, ce qui existe réellement et ce qui n'existe
pas encore.

> **Contrat HTTP machine-readable** : ce document explique les décisions et
> limitations métier (pourquoi tel champ n'existe pas, pourquoi tel endpoint
> est déconseillé...) ; le schéma exact de chaque endpoint (paramètres,
> réponses, exemples, essai interactif avec un vrai token) est généré depuis
> le code via **OpenAPI/Swagger** (`dedoc/scramble`) : `/docs/api` en local
> (Nuxt/mobile, Bearer Sanctum), export `docs/openapi/client.json`. Les
> endpoints server-to-server de la vitrine ont leur propre document séparé
> (`/docs/vitrine`, clé `X-Vitrine-Key`) — voir le rapport OpenAPI du
> 27/08/2026 pour le détail des choix (périmètre, sécurité, environnements).
> Ce Markdown n'est jamais resynchronisé automatiquement avec la spec
> générée — en cas de doute sur un champ précis, la spec générée fait foi.

## 0. Architecture

- **Mécanisme** : Laravel Sanctum, Personal Access Token (Bearer), **pas** de
  cookie de session, **pas** de CORS configuré. Un navigateur ne doit jamais
  appeler ces routes directement.
- **Intégration recommandée (Nuxt)** : BFF via Nitro — `server/api/*` appelle
  ces endpoints en server-to-server avec `Authorization: Bearer <token>`, le
  token étant scellé dans un cookie httpOnly propre à Nuxt, jamais exposé au JS
  navigateur.
- **Base URL** : `https://fello.eau-la-maman.com/api` (prod) —
  `/v1/mobile/...`, `/gains/mine`, `/livraisons/en-cours` sont **sous** ce
  préfixe `/api` malgré l'absence de `/api` visible dans leur propre chemin
  (défini dans `routes/api.php`, monté sous `/api` par Laravel).
- **Guard unique** : `auth:sanctum` sur toutes les routes de ce document sauf
  mention contraire. Un token valide **et non expiré** est nécessaire ; en plus,
  la plupart des routes "métier" exigent le rôle Spatie `client`, `proprietaire`
  ou `livreur` (`role:client|proprietaire|livreur` — un token valide seul ne
  suffit pas, cf. §2).
- **Isolation** : chaque endpoint dérive l'organisation/le profil métier
  exclusivement de `$request->user()` via `App\Services\Client\
  ClientIdentityResolver` — jamais d'un paramètre envoyé par le client. Un
  compte peut cumuler un rôle staff (`super_admin`, `admin_entreprise`...) ET
  un rôle client/proprietaire/livreur sur le **même** compte (décision du
  26/08/2026) ; dans ce cas, priorité d'affichage `proprietaire` > `client` >
  `livreur` sur les endpoints qui ne concernent qu'un seul profil à la fois.

---

## 1. Authentification

### `POST /api/auth/login`

```json
// Requête
{ "telephone": "+224620000100", "password": "...", "device_name": "elm-nuxt-web" }
```

- `device_name` obligatoire (max 255) — nomme le token créé, un par appareil/client
  (`elm-mobile-android`, `elm-mobile-ios`, `elm-nuxt-web`...). Se connecter
  depuis un nouvel appareil **ne déconnecte jamais** les autres.
- `200` : `{ "token": "...", "user": {id, prenom, nom, telephone, email, roles} }`
- `422` : identifiants invalides (format standard Laravel `{message, errors}`)
- `403` : compte non éligible — voir tableau §1.2
- `429` : throttle `10` tentatives/minute (IP)

### `GET /api/auth/me` (auth:sanctum)

Identité minimale + IDs de contexte — appelé sur presque tous les écrans, doit
rester léger.

```json
{
  "id": "...", "prenom": "Moussa", "nom": "SIDIBÉ",
  "telephone": "+224622602693", "email": "...",
  "roles": ["super_admin", "proprietaire"],
  "is_active": true,
  "qr_payload": "https://fello.../proprietaires/xxx",
  "context": {
    "organization_id": "...",
    "client_id": null,
    "proprietaire_id": "...",
    "livreur_id": null
  }
}
```

`roles` liste **tous** les rôles du compte (jamais un seul "rôle principal") —
un frontend doit toujours vérifier `roles.some(r => ['client','proprietaire','livreur'].includes(r))`
pour décider de l'accès à l'espace client, jamais `roles[0]` ni une égalité stricte.

### `POST /api/auth/logout` (auth:sanctum)

Révoque **uniquement le token courant**. Les autres appareils restent connectés.

### `POST /api/auth/logout-all` (auth:sanctum)

Révoque **tous** les tokens du compte (tous appareils). Pour un écran "Sécurité"
ou en cas de suspicion de compromission.

### `POST /v1/mobile/auth/change-password` (auth:sanctum)

```json
{ "current_password": "...", "password": "...", "password_confirmation": "..." }
```

Révoque tous les **autres** tokens à la réussite (garde le token courant actif)
— protège un compte si un autre appareil avait un token compromis.
`422` si `current_password` incorrect.

### Connexion sans mot de passe par OTP (ajouté le 27/08/2026)

Alternative à `POST /api/auth/login` pour un compte qui préfère un code à usage
unique plutôt qu'un mot de passe — **deux appels**, jamais un seul.

**Étape 1 — `POST /api/auth/otp-login/request`**

```json
// Requête
{ "telephone": "+224620000100" }
```

```json
// 200 (Nimba SMS configuré, cas par défaut depuis le 31/08/2026)
{ "sent": true, "channel": "sms", "destination_masked": "+224 ••••••• 00", "cooldown_seconds": 30 }
// 200 (repli email si Nimba indisponible/mal configuré)
{ "sent": true, "channel": "email", "destination_masked": "j***@gmail.com", "cooldown_seconds": 30 }
```

- `channel` indique **par où** le code vient réellement d'être envoyé — utilisez-le
  pour le message affiché ("Code envoyé par SMS au +224 ••• •• 93") plutôt que
  de supposer un canal fixe. **Depuis le 31/08/2026, `channel` vaut `"sms"`**
  dès que le fournisseur Nimba SMS est configuré côté serveur (variables
  `NIMBA_SMS_*`) — le téléphone étant toujours connu (c'est l'identifiant de
  connexion), SMS est systématiquement choisi avant email pour ce parcours
  (whatsapp n'est pas encore branché). `channel` ne redevient `"email"` que si
  Nimba est indisponible/mal configuré (repli automatique, cf.
  `App\Services\Otp\OtpChannelResolver`). Ne codez jamais en dur
  `channel === 'email'` comme condition d'affichage, traitez `channel` comme
  une valeur parmi `"email" | "sms" | "whatsapp"`.
- `destination_masked` (ajouté le 27/08/2026, demande front) : la coordonnée
  **réellement utilisée** pour ce canal, **déjà masquée côté serveur**
  (`App\Services\Otp\OtpDestinationMasker`) — un email donne `"j*******@example.com"`
  (premier caractère local visible, reste masqué, domaine intact) ; un futur
  SMS/WhatsApp donnerait `"+224 ••• •• 26 93"` (indicatif + 2 derniers chiffres
  visibles). N'inventez jamais cette valeur côté frontend (pas de
  reconstruction depuis une autre source, pas d'email deviné) — c'est
  précisément pour éviter ça que ce champ existe : le backend seul connaît
  la vraie coordonnée.
- `404` : `{ "error": "Aucun compte trouvé pour ce numéro de téléphone." }` —
  aucun compte n'est lié à ce numéro (proposez l'inscription, pas un nouvel essai).
- `429` : `{ "error": "...", "retry_after_seconds": N }` — anti-spam
  (cooldown 30s + plafonds horaire/journalier, mêmes limites que le reste des
  parcours OTP de l'app).
- `503` : `{ "error": "..." }` — **aucun canal n'est utilisable pour ce compte
  précis** (cas réel aujourd'hui : le compte n'a pas d'adresse email connue, et
  email est l'unique canal actif). Affichez un message du type "Impossible de
  vous envoyer un code pour le moment, contactez le support" — ce n'est pas une
  erreur de saisie, ne réessayez pas automatiquement.

**Étape 2 — `POST /api/auth/otp-login/verify`**

```json
// Requête
{ "telephone": "+224620000100", "code": "123456", "device_name": "elm-nuxt-web" }
```

- `200` : **même forme exacte que `POST /api/auth/login`** —
  `{ "token": "...", "user": {id, prenom, nom, telephone, email, roles} }`.
  Traitez le succès de cette route exactement comme un login classique côté
  frontend (stockage du token, redirection...).
- `422` : `{ "error": "Code incorrect ou expiré." }` — code faux, expiré (10 min),
  ou déjà utilisé (usage unique).
- `429` : `{ "error": "Trop de tentatives. Demandez un nouveau code." }` — verrouillé
  après 5 essais incorrects, il faut relancer l'étape 1.
- `403` : `{ "message": "...", "code": "..." }` — **même tableau de statuts de
  compte que le login mot de passe** (§1.2 : `pending_validation`,
  `account_blocked`...), vérifié uniquement une fois le code validé.

**Point important, invisible dans la réponse mais à ne jamais présumer côté
UI** : une connexion réussie par ce parcours **ne vérifie jamais le numéro de
téléphone** au sens strict — si le code est arrivé par email (cas d'aujourd'hui),
cela authentifie la session mais ne prouve pas la possession du numéro. Ne
retirez donc jamais un éventuel badge/état "téléphone non vérifié" affiché
ailleurs dans l'app suite à une simple connexion OTP réussie.

### 1.1 Politique de tokens

| Aspect | Règle |
|---|---|
| Expiration | 90 jours par défaut (`SANCTUM_EXPIRATION_MINUTES`), configurable par environnement |
| Multi-device | Illimité, un token indépendant par `device_name` |
| Logout | Token courant seulement |
| Logout-all | Tous les tokens |
| Changement mdp (connecté) | Tous les **autres** tokens révoqués, le courant reste valide |
| Reset mdp (mot de passe oublié, OTP) | **Tous** les tokens révoqués sans exception |
| Compte désactivé après émission du token | Coupe l'accès immédiatement sur **toute** route `auth:sanctum` (pas seulement au prochain login) |

### 1.2 Statuts de compte (403 au login, et sur toute route ensuite)

| `code` | Signification | Résolution |
|---|---|---|
| `pending_validation` | En attente de validation par un admin | Action admin |
| `account_blocked` | Compte désactivé | Réactivation admin |
| `email_not_verified` | Email non confirmé (auto-inscription) | L'utilisateur clique le lien reçu par email |

### 1.3 Contrat d'erreur générique

- `422` — validation : `{ "message": "...", "errors": { "champ": ["..."] } }`
- `401` — non authentifié : `{ "message": "Non authentifié." }`
- `403` — refusé (rôle manquant, compte non éligible, ressource d'un autre compte) : `{ "message": "...", "code"?: "..." }`
- `404` — ressource introuvable ou non rattachée à ce compte (jamais un 403 qui confirmerait son existence pour quelqu'un d'autre)

---

## 2. Garde de rôle sur les routes métier

Les routes ci-dessous (§3 à §7, sauf mention contraire) exigent, **en plus**
de `auth:sanctum`, le rôle Spatie `client`, `proprietaire` ou `livreur`
(`role:client|proprietaire|livreur`). Un compte purement staff (ex: `["super_admin"]`
sans profil métier lié) reçoit **403** sur ces routes, même avec un token valide.
Un compte cumulant staff + un rôle métier est accepté normalement.

---

## 3. Profil

### `GET /v1/mobile/profile`

Fiche complète (identité, entreprise si applicable, contact, localisation,
préférences de notification) — distincte de `/me`, à charger seulement sur
l'écran "Mon profil".

```json
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

- `profile.type` vaut `"proprietaire"`, `"client"` ou `"livreur"` selon le
  profil réel (priorité proprietaire > client > livreur si cumul).
- Propriétaire entreprise : `entreprise: { "raison_sociale": "..." }` (jamais
  d'objet vide, `null` sinon).
- `profile` vaut `null` si le rôle est présent mais qu'aucune fiche métier n'est
  réellement rattachée (cas limite, ne devrait pas arriver en usage normal).
- **Pas de champ `siret`/identifiant légal** — notion absente du modèle ELM, ne
  jamais l'afficher/l'inventer côté frontend.
- **Pas de champ "quartier" séparé** — seuls `pays`, `ville`, `adresse`
  existent ; un quartier doit être saisi dans `adresse` en texte libre.

### `PATCH /v1/mobile/profile`

Modifie **uniquement** la localisation :

```json
// Requête (champs tous optionnels, seuls les envoyés sont modifiés)
{ "pays": "Guinée", "code_pays": "GN", "ville": "Kindia", "adresse": "Quartier Manquépas" }
```

Réponse : identique à `GET /v1/mobile/profile`. `404` si aucun profil rattaché.

**Non modifiables par ce endpoint** (silencieusement ignorés si envoyés) :
`nom`/`prenom`/`surnom` (identité civile), `telephone`/`email` (identifiants de
connexion), `raison_sociale`/`type` (identité légale), `actif` — réservés au
backoffice.

### `PATCH /v1/mobile/profile/notification-preferences`

```json
// Requête
{ "preferences": { "activite": false } }
// Réponse
{ "notifications": { "activite": false } }
```

Persisté en base (`users.notification_preferences`) — jamais dans un
`localStorage` local à l'appareil, commun à tous les clients (Nuxt/PWA/mobile).
Catégorie jamais réglée = activée par défaut. Toute clé inconnue est
silencieusement ignorée (liste blanche fermée, une seule catégorie existe
aujourd'hui : `activite`).

Distinct de la permission système du navigateur/téléphone pour les push
(`Notification.requestPermission()`, gérée côté frontend) et du jeton technique
`expo_push_token` (§7) qui sert à *router* le push, pas à décider si
l'utilisateur en veut.

---

## 4. Véhicules

### `GET /v1/mobile/vehicules/mine`

Liste des véhicules du proprietaire (les siens) ou du livreur (ceux de son
équipe) — jamais toute la flotte de l'organisation.

```json
[
  {
    "id": "...", "nom": "ABARRY", "immatriculation": "OU3859",
    "type": "Camion", "capacite": 500, "is_active": true,
    "photo_url": "https://.../api/vehicules/xxx/photo",
    "en_livraison": false,
    "role": "proprietaire",
    "conducteur": "Issa M."
  }
]
```

- `conducteur` (ajouté le 26/08/2026) : nom du membre d'équipe au rôle
  `chauffeur`, `null` si aucune équipe ou aucun chauffeur assigné.
- **Pas de statut "Entretien"/maintenance** — seul `is_active` (booléen)
  existe dans le modèle ELM. N'affichez pas un statut de ce type sans colonne
  backend dédiée (elle n'existe pas).
- `capacite` est un champ hérité (nombre unique, packs) — la capacité réelle
  multi-catégorie vit ailleurs (non exposée ici).

### `GET /v1/mobile/vehicules/{vehiculeId}/commissions`

Commissions de vente pour ce véhicule précis (`404` si le véhicule n'appartient
pas à l'appelant) :

```json
[
  {
    "id": "...", "reference": "CMD-2847", "date": "2026-08-20T00:00:00.000000Z",
    "montant_net": 50000, "montant_a_payer": 50000, "montant_verse": 20000,
    "montant_restant": 30000, "statut": "partiel", "mois": "Août 2026"
  }
]
```

### `GET /v1/mobile/vehicules/{vehiculeId}/frais`

Dépenses validées pour ce véhicule précis (`404` si non rattaché à l'appelant) :

```json
[
  {
    "id": "...", "date": "2026-08-22", "montant": 68400,
    "type_code": "carburant", "type_label": "Carburant",
    "statut": "validee", "commentaire": null, "mois": "Août 2026"
  }
]
```

### `GET /v1/mobile/depenses/mine`

Version **consolidée** (tous véhicules accessibles, une seule requête, pas de
N+1) de l'endpoint ci-dessus — à utiliser pour une page "Mes dépenses" globale.
Accessible au proprietaire **et** au livreur (même périmètre que
`/vehicules/{id}/frais`, dont c'est la généralisation) — volontairement plus
large que le calcul de solde du dashboard (`GET /v1/mobile/dashboard`, §5) qui,
lui, ignore les dépenses côté livreur.

Query params (tous optionnels) :

| Param | Effet |
|---|---|
| `vehicule_id` | Restreint à un seul véhicule (parmi ceux accessibles — sinon liste vide) |
| `depense_type_id` | Filtre par type de dépense (catégorie) |
| `statut` | Valeur de `StatutDepense` : `brouillon`, `soumis`, `valide`, `rejete`, `annule` — **aucun filtre par défaut**, contrairement au calcul du dashboard qui ne compte que `valide` |
| `date_debut`, `date_fin` | Filtre par `date_depense` — **aucune période par défaut** (liste complète si non fourni) |
| `per_page` | 1 à 100, défaut 20 |

```json
{
  "data": [
    {
      "id": "...", "date": "2026-08-22", "montant": 68400,
      "type_code": "carburant", "type_label": "Carburant",
      "statut": "valide", "statut_label": "Validé",
      "commentaire": null,
      "vehicule": { "id": "...", "nom_vehicule": "ABARRY", "immatriculation": "OU3859" }
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 42 },
  "filters": { "vehicule_id": null, "depense_type_id": null, "statut": null, "date_debut": null, "date_fin": null }
}
```

Pagination Laravel standard (`links`/`meta`) — `filters` reflète les filtres
réellement appliqués (utile pour resynchroniser l'UI après un rechargement).

### `GET /v1/mobile/propositions-vehicules` et `POST /v1/mobile/propositions-vehicules`

Proposer un véhicule partenaire (formulaire "Proposer un véhicule") — **même
moteur** que la page Inertia (`VehicleProposalService`, extrait le 26/08/2026
de `ClientDashboardController::storeVehicleProposal()`) : même règle
anti-doublon, même normalisation d'immatriculation, même traitement image.
Noms de route volontairement `propositions-vehicules.*` et non
`propositions.*` : `routes/web.php` a déjà `client.propositions.index`/
`client.propositions.store` pour les pages Inertia (même piège de collision
que `client.dashboard`/`client.profile`, cf. §5).

```
POST /v1/mobile/propositions-vehicules
Content-Type: multipart/form-data
```

| Champ | Requis | Note |
|---|---|---|
| `immatriculation` | oui | Normalisée en MAJUSCULES côté backend |
| `type_vehicule` | oui | — |
| `photo` | oui | Image, 5 Mo max, convertie en WebP |
| `nom_vehicule`, `marque`, `modele`, `commentaire` | non | — |

`201` avec la proposition créée (`{"data": {...}}`, wrapping standard d'une
ressource unique) ; `422` si une proposition **en attente** existe déjà pour
cette immatriculation (message sur le champ `immatriculation`, même règle que
l'Inertia — pas un simple message générique) ; `422` standard Laravel pour tout
champ manquant/invalide.

`GET /v1/mobile/propositions-vehicules` liste les propositions du compte
connecté (tous statuts, 20 dernières) — pas de pagination pour l'instant
(volume attendu faible par compte, contrairement aux dépenses/commandes).

---

## 5. Gains et livraisons

### `GET /v1/mobile/dashboard`

Dashboard financier consolidé (vente + logistique + dépenses + solde) — **même
moteur** que l'espace client Inertia (`App\Services\Client\ClientEarningsService`,
extrait le 26/08/2026 de `ClientDashboardController`) : backoffice web et cette
route renvoient garanti les mêmes montants pour les mêmes filtres (couvert par
des tests de parité, `tests/Feature/Api/Client/DashboardControllerTest.php`).
C'est l'endpoint à utiliser pour un tableau de bord propriétaire/livreur — pas
`/gains/mine` (voir avertissement plus bas).

Query params (tous optionnels) :

| Param | Valeurs | Défaut | Effet |
|---|---|---|---|
| `period` | `7j`, `30j`, `ce_mois`, `mois_passe`, `custom` | `ce_mois` | Raccourci de période ; `custom` utilise `date_debut`/`date_fin` tels quels |
| `date_debut`, `date_fin` | date ISO | — | Utilisés seulement si `period=custom` |
| `vehicule_id` | ULID d'un véhicule accessible | — | Restreint le **calcul** des montants à ce véhicule (voir note ci-dessous) |
| `statut` | valeur de `StatutCommission` (`impaye`, `partiel`, `paye`...) | — | Filtre les commissions par statut |

```json
{
  "filters": { "period": "ce_mois", "date_debut": "2026-08-01", "date_fin": "2026-08-26", "vehicule_id": null, "statut": null },
  "summary": {
    "total_earned": 23000, "total_paid": 11000,
    "frais_depenses_total": 4000, "balance": 8000,
    "operations_count": 2
  },
  "summary_evolution": {
    "total_earned": { "previous_value": 20000, "percent": 15.0, "direction": "up", "comparable": true },
    "total_paid": { "previous_value": 5000, "percent": 120.0, "direction": "up", "comparable": true },
    "frais_depenses_total": { "previous_value": 4000, "percent": 0.0, "direction": "stable", "comparable": true },
    "balance": { "previous_value": 0, "percent": null, "direction": "up", "comparable": false },
    "operations_count": { "previous_value": 1, "percent": 100.0, "direction": "up", "comparable": true }
  },
  "comparison_period": { "date_debut": "2026-07-01", "date_fin": "2026-07-31" },
  "par_vehicule": [
    { "vehicule_id": "...", "nom_vehicule": "ABARRY", "immatriculation": "OU3859",
      "frais_depenses": 4000, "total_earned": 23000, "total_paid": 11000, "balance": 8000 }
  ],
  "vehicules": [
    { "id": "...", "nom_vehicule": "ABARRY", "immatriculation": "OU3859" }
  ]
}
```

- `summary` : totaux agrégés, mêmes clés que l'espace client Inertia
  (`earnings`) — noms de champs du moteur réel, pas une reformulation.
  `balance` ne descend jamais sous 0 (un solde négatif n'est jamais affiché
  comme dette du propriétaire, comportement du moteur, pas de cet endpoint).
- `summary_evolution` (ajouté le 27/08/2026, champ **additif** — `summary`
  lui-même ne change pas) : évolution de chacun des 5 champs de `summary`.
  **Le pourcentage compare la période sélectionnée à la période
  immédiatement précédente de même durée** — jamais "le mois précédent"
  arbitraire. Exemples : `01/08→31/08` (31 jours) est comparé à
  `01/07→31/07` (31 jours) ; `10/08→16/08` (7 jours) est comparé à
  `03/08→09/08` (7 jours). `direction` (`up`/`down`/`stable`) est
  **factuelle**, jamais un jugement métier : une hausse de
  `frais_depenses_total` vaut `up` exactement comme une hausse de
  `total_earned` — c'est au frontend de décider, KPI par KPI, si une hausse
  donnée est bonne ou mauvaise. Quand la période précédente valait 0 et que
  la période actuelle est non nulle, le pourcentage n'est pas défini
  mathématiquement : `percent` vaut `null` et `comparable` vaut `false`
  (jamais `Infinity`/`999999`/`100` en substitut) — `direction` reste
  renseignée pour afficher une flèche, typiquement à côté d'un texte comme
  "Nouveau" plutôt que d'un pourcentage. `summary_evolution` et
  `comparison_period` valent tous les deux `null` uniquement dans le cas
  dégénéré `period=custom` sans `date_debut`/`date_fin`.
- `comparison_period` : bornes exactes de la période précédente utilisée par
  `summary_evolution`, pour affichage (ex. "vs 01/07 - 31/07").
- `par_vehicule` : **liste toujours l'intégralité du parc accessible**, même
  quand `vehicule_id` est fourni — seul le **calcul** des montants est
  restreint au véhicule filtré (les autres véhicules apparaissent avec des
  montants à 0). Même comportement que le dashboard Inertia ; ne pas
  interpréter l'absence de filtrage de la liste comme un bug. Pas
  d'évolution par véhicule dans ce lot (cf. rapport du 27/08/2026) : le KPI
  global était la priorité, une évolution par véhicule nécessiterait de
  faire évoluer `VehiculeEarningsRow` (constructeur + 2 points d'appel) pour
  un bénéfice non demandé par l'écran actuel.
- `vehicules` : parc accessible complet (identité seulement, pour peupler un
  sélecteur de véhicule côté frontend).
- Rôle requis : `client|proprietaire|livreur` (comme le reste de cette
  section). Un compte staff pur reçoit `403`.

### `GET /gains/mine`

Résumé des commissions **de vente** (véhicule) :

```json
{
  "total_brut": 5750000, "total_net": 5400000, "total_a_payer": 5135800,
  "total_verse": 4100000, "total_restant": 1035800, "nb_commandes": 42,
  "par_vehicule": [
    { "vehicule_id": "...", "nom": "ABARRY", "immatriculation": "OU3859",
      "total_brut": 2380000, "total_net": 2200000, "total_a_payer": 2100000,
      "total_verse": 1500000, "total_restant": 600000, "nb_commandes": 18 }
  ]
}
```

⚠️ **Endpoint historique, déconseillé pour tout nouvel écran** — préférer
`GET /v1/mobile/dashboard` ci-dessus. `/gains/mine` **n'inclut PAS les
commissions logistiques** (`CommissionLogistiquePart`), uniquement les
commissions de vente (`CommissionEnveloppePart`), et **n'inclut pas les
dépenses** — un livreur/proprietaire dont les gains viennent uniquement de
logistique verra `0` ici alors que `/v1/mobile/dashboard` affiche le bon
montant. Moteur de calcul entièrement distinct (requête SQL propre à ce
contrôleur, jamais partagée avec `ClientEarningsService`) — conservé tel quel
pour ne pas casser un contrat mobile existant, pas de plan de suppression
pour l'instant.

### `GET /livraisons/en-cours`

⚠️ **Ne renvoie QUE les livraisons en transit** (véhicules du proprietaire et
équipe du livreur) — ni les livraisons terminées, ni celles à vérifier. Ne
peut pas alimenter une page "Activité" historique à lui seul.

```json
[
  {
    "id": "...", "reference": "CMD-2847", "statut": "commande",
    "statut_label": "Commande en cours", "site_source": "Siège de Matoto",
    "site_destination": "Client X", "vehicule": { "nom": "ABARRY", "immatriculation": "OU3859" },
    "equipe_nom": "ABARRY", "date_depart": "2026-08-26", "date_arrivee_prevue": null,
    "nb_packs": 12
  }
]
```

Mélange deux concepts différents dans la même liste : commandes de vente
(`statut: "commande"`) et transferts logistiques (`statut` = valeur de
`StatutTransfert`) — le frontend doit les distinguer par ce champ `statut`
s'il veut les traiter différemment.

### `GET /v1/mobile/livraisons-transferts?tab=en_cours|historique` (livreur uniquement, via équipe)

Existe **déjà** un historique complet côté livreur (statuts
réception/clôture/annulé inclus avec `tab=historique`) — mais scopé aux
transferts logistiques de l'équipe du livreur, **pas** aux ventes, et **pas**
accessible à un proprietaire pur (résolution par équipe du livreur uniquement,
pas par véhicule). Retourne une collection de `TransfertResource` (mêmes champs
que `/livraisons/en-cours` pour la partie transfert, plus riche : lignes,
activités, commission).

Sous-routes associées (workflow livreur, écrites) :
- `GET /v1/mobile/livraisons-transferts/{transfert}` — détail
- `POST /v1/mobile/livraisons-transferts/{transfert}/demarrer-chargement`
- `PUT /v1/mobile/livraisons-transferts/{transfert}/quantites-chargees`
- `POST /v1/mobile/livraisons-transferts/{transfert}/confirmer-depart`

Ces 4 routes sont le workflow de chargement/départ d'un livreur — plus
pertinentes pour l'app mobile livreur que pour un espace client web
propriétaire/client. **Note technique** : ce contrôleur résout le livreur par
téléphone en repli comme l'ancien code déjà corrigé ailleurs (pas encore
migré vers `ClientIdentityResolver`) — à surveiller si un jour un livreur signale
un souci d'accès similaire à celui déjà rencontré côté proprietaire.

### `GET /v1/mobile/activite`

Historique complet (**tous statuts**, pas seulement "en cours") des commandes
de vente **et** transferts logistiques d'un propriétaire — comble exactement
le manque ci-dessus : contrairement à `/v1/mobile/livraisons-transferts`, cet
endpoint résout aussi **par véhicule** (pas seulement par équipe de livreur),
donc fonctionne pour un **proprietaire pur sans équipe**. Un livreur reste
accueilli (même résolution véhicule/équipe que `/livraisons/en-cours`), mais
ce n'est pas son usage principal — pour un livreur, préférer
`/v1/mobile/livraisons-transferts?tab=historique`.

Query params (tous optionnels) :

| Param | Effet |
|---|---|
| `type` | `vente` ou `logistique` — omis = les deux mélangés, triés par date décroissante |
| `statut` | **Exige `type`** (422 sinon, avec message explicite) — les deux modèles ont des vocabulaires de statut différents (`StatutCommandeVente` vs `StatutTransfert`), aucune correspondance n'est inventée entre les deux |
| `vehicule_id` | Restreint à un seul véhicule (parmi ceux accessibles) |
| `date_debut`, `date_fin` | Filtre sur `validated_at` (vente) ou `date_depart_reelle` (logistique) |
| `per_page` | 1 à 100, défaut 20 |

```json
{
  "data": [
    {
      "id": "...", "type": "vente", "reference": "CMD-2847",
      "statut": "livraison_en_cours", "statut_label": "Livraison en cours",
      "site_source": "Siège de Matoto", "site_destination": "Client X",
      "vehicule": { "id": "...", "nom_vehicule": "ABARRY", "immatriculation": "OU3859" },
      "date": "2026-08-20", "nb_packs": 12
    },
    {
      "id": "...", "type": "logistique", "reference": "TR-00042-XYZ",
      "statut": "cloture", "statut_label": "Clôturé",
      "site_source": "Siège de Matoto", "site_destination": "Dépôt Kindia",
      "vehicule": { "id": "...", "nom_vehicule": "ABARRY", "immatriculation": "OU3859" },
      "date": "2026-08-18", "nb_packs": 40
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 2, "per_page": 20, "total": 27 },
  "filters": { "type": null, "statut": null, "vehicule_id": null, "date_debut": null, "date_fin": null }
}
```

⚠️ **Pagination calculée côté backend en mémoire**, pas par une requête SQL
unique : les deux modèles (`CommandeVente`, `TransfertLogistique`) sont
interrogés séparément (chacun filtré et borné à 200 lignes), fusionnés puis
triés par date avant découpage en pages. Le volume réel reste borné à
l'activité d'un seul propriétaire — jamais un souci de performance en usage
normal, mais à garder en tête si un jour un compte cumule plusieurs milliers
d'opérations sur une seule période demandée.

### `GET /v1/mobile/livraisons/scan/{reference}`

Résout une référence scannée (QR). Depuis le 31/08/2026, le préfixe dépend du processus
d'origine (cf. `docs/references-metier.md`) : `VTE-`/`DST-`/legacy `CMD-` = commande de vente
(vente standard ou distribution client), `TRF-`/legacy `TR-` = transfert logistique — les
anciennes références ne sont jamais renommées et restent reconnues indéfiniment. `404` si
préfixe non reconnu ou référence introuvable **ou appartenant à une autre organisation**. Pas de
garde de rôle explicite (`auth:sanctum` seul), mais la recherche est systématiquement filtrée par
`organization_id` de l'utilisateur authentifié, sur les deux méthodes privées — corrigé le
31/08/2026 : la numérotation scopée par organisation (cf. `docs/references-metier.md`) rend deux
organisations capables de porter *exactement* la même référence (ex: `VTE-310826-001` pour l'une
ET l'autre), ce qui aurait sinon rendu une recherche non scopée fonctionnellement ambiguë (retour
possible des données d'une autre organisation), pas seulement un défaut d'isolation préexistant.

---

## 6. Commandes (rôle `client`)

Premier endpoint API dédié au rôle `client` (achats) — jusqu'ici tous les
endpoints `Client\*` étaient orientés proprietaire/livreur (gap documenté
en §9). Résolution exclusivement via `ClientIdentityResolver` → `identity->client`
— **jamais** un `client_id` fourni par l'appelant.

### `GET /v1/mobile/commandes/mine`

```json
{
  "data": [
    {
      "id": "...", "reference": "CMD-2847", "statut": "livree", "statut_label": "Livrée",
      "date": "2026-08-20", "total_commande": 45000,
      "vehicule": { "id": "...", "nom_vehicule": "ABARRY", "immatriculation": "OU3859" }
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 },
  "filters": { "statut": null, "date_debut": null, "date_fin": null }
}
```

Query params optionnels : `statut` (`StatutCommandeVente`), `date_debut`/
`date_fin` (sur `validated_at`), `per_page` (1-100, défaut 20). Un compte sans
profil Client (proprietaire/livreur purs) reçoit une liste **vide** — cohérent
avec le reste de l'API (un profil non applicable renvoie du vide, pas une
erreur) — mais un multi-rôle staff+client voit normalement ses commandes.

### `GET /v1/mobile/commandes/{commandeId}`

Détail avec lignes (`{"data": {..., "lignes": [...]}}`, wrapping standard
Laravel pour une ressource unique — différent de la liste ci-dessus qui n'est
PAS wrappée dans une clé `data` supplémentaire au-delà de la pagination).
`404` si la commande n'appartient pas au client résolu — jamais un 403 qui
confirmerait son existence pour un autre compte (cf. §1.3). Les lignes
utilisent les **snapshots** enregistrés à la commande (`libelle_snapshot`,
`prix_vente_snapshot`), jamais une re-jointure vers le catalogue produit
actuel (un prix modifié depuis ne doit jamais réécrire l'historique).

```json
{
  "data": {
    "id": "...", "reference": "CMD-2847", "statut": "livree", "statut_label": "Livrée",
    "date": "2026-08-20", "total_commande": 45000,
    "vehicule": { "id": "...", "nom_vehicule": "ABARRY", "immatriculation": "OU3859" },
    "lignes": [
      { "id": "...", "libelle": "Pack Eau 1.5L", "quantite_demandee": 10, "quantite_livree": 10,
        "prix_vente_snapshot": 5000, "total_ligne": 50000 }
    ]
  }
}
```

## 7. Notifications

### `GET /v1/mobile/notifications`

Contrat finalisé le 27/08/2026 (cf. §7.3) — normalisé par
`App\Http\Resources\Api\Mobile\NotificationResource`, quelle que soit la
classe `Notification` Laravel d'origine. Pagination Laravel standard
(`?per_page=`, défaut 20, max 100 — mêmes bornes que
`GET /v1/mobile/depenses/mine`), tri décroissant sur `created_at`.

```json
{
  "data": [
    {
      "id": "0199...",
      "type": "commission.generated",
      "titre": "Commission générée",
      "message": "175 500 GNF — Réf. CMD-2841",
      "montant": 175500,
      "resource": { "type": "commande_vente", "id": "0199..." },
      "lu": false,
      "read_at": null,
      "created_at": "2026-08-27T10:00:00.000000Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 47, "...": "..." },
  "unread_count": 3
}
```

- `type` : identifiant technique **stable**, jamais le nom de la classe PHP
  ni l'ancien type snake_case interne (table de mapping ci-dessous, §7.3).
- `montant` : nombre brut (jamais formaté "175 500 GNF") — `null` si non
  pertinent pour ce type.
- `resource` : `{type, id}` navigable si pertinent, `null` sinon — jamais un
  ID fabriqué.
- `lu`/`read_at` : `read_at` est la colonne native Laravel `DatabaseNotification.read_at`.
- Le champ `data` (payload brut Laravel) **n'existe plus** dans la réponse —
  remplacé par `montant`/`resource` (seul retrait du contrat, cf. §7.3 pour la
  justification et la compatibilité avec les lignes déjà en base).

**Aucune garde de rôle** sur ces 3 routes (`auth:sanctum` seul suffit) —
`NotificationDispatcher` (§7.1) a déjà décidé qui reçoit quoi au moment du
dispatch ; le controller ne refait jamais cette matrice.

### `POST /v1/mobile/notifications/mark-all-read`

Marque toutes les notifications non lues **du compte authentifié uniquement**
comme lues. Idempotent. Réponse : `{ "success": true, "unread_count": 0 }`.

### `POST /v1/mobile/notifications/{id}/read`

Marque une notification précise comme lue. **404** (jamais 403) si l'ID
n'appartient pas au compte authentifié — même convention que
`GET /v1/mobile/commandes/{commandeId}` (n'expose jamais si l'ID existe pour
un autre compte). Idempotent (second appel sans effet). Réponse :
`{ "success": true, "data": <NotificationResource>, "unread_count": N }`.

### `POST /v1/mobile/push-token`

```json
{ "expo_push_token": "ExponentPushToken[...]" }
```

Enregistre le jeton technique Expo pour le push — distinct de la préférence
métier `notification_preferences` (§3). Écrit sur `users.expo_push_token`.

### 7.1 Architecture notifications — phase 1 (2026-08-27)

Suite à l'audit du 26/08/2026 (ci-dessous pour mémoire), une architecture
centralisée a été construite pour les contextes réellement connectés
aujourd'hui — **client, propriétaire, livreur, staff** — le rôle `prestataire`
reste explicitement hors périmètre (pas de compte connecté, pas de `user_id`
sur `Prestataire`, pas de cible commission).

Deux classes partagées (`app/Services/Notification/`) :
- `BeneficiaireUserResolver::resolve($type, $id)` — résout un `User` réel à
  partir du couple `beneficiaire_type`/`beneficiaire_id` (`proprietaire`,
  `livreur`, avec repli téléphone `UserAuthIdentity::resoudre` si `user_id` est
  null) — vocabulaire commun à `CommissionEnveloppePart`,
  `CommissionLogistiquePart`, `PaiementFiche`, `DepenseImputation`. `site` /
  `salarie` / `prestataire` n'ont aucun compte : renvoie toujours `null`, sans
  erreur.
- `NotificationDispatcher::send($notification, $recipients, $category, $pushPayload)`
  — dédoublonne les destinataires par id, filtre par préférence
  (`User::wantsNotification()`), notifie, puis pousse en Expo si un payload
  push est fourni. Remplace la collecte dupliquée qui existait dans chaque
  Job/Service.

**Préférences** (`User::NOTIFICATION_PREFERENCE_DEFAULTS`) étendues à
`livraisons`, `commissions`, `depenses`, en plus de l'ancienne clé unique
`activite` — conservée comme repli de compatibilité :
`wantsNotification($categorie)` retourne `$prefs[$categorie] ?? $prefs['activite'] ?? true`.
Un compte qui n'a jamais réglé que `activite` continue donc de s'appliquer à
toutes les catégories ; `PATCH /v1/mobile/profile/notification-preferences`
reste inchangé (seule `activite` y est exposée pour l'instant).

**Événements couverts** (canal `database`, + push Expo pour les 2 premiers) :

| Événement | Destinataire(s) | Catégorie |
|---|---|---|
| Commande validée (`CommandeValideeNotification`) | Livreurs de l'équipe **uniquement** — le propriétaire ne le reçoit plus (affectation logistique, pas financière ; décision produit 2026-08-27) | `livraisons` |
| Transfert créé (`TransfertCreeNotification`, nouveau) | Livreurs de l'équipe | `livraisons` |
| Transfert réceptionné (`TransfertReceptionneeNotification`, nouveau) | Propriétaire du véhicule (jamais le livreur, qui vient d'effectuer l'action) | `livraisons` |
| Commission générée (`CommissionGenereeNotification`, nouveau ; vente et logistique) | Propriétaire + livreur(s) réellement connectés — jamais `site`/`consultant` (aucun compte) | `commissions` |
| Commission payée (`CommissionPayeeNotification`, réactivée) | Bénéficiaire réel, sur les 3 chemins de paiement (direct logistique, versement legacy, paiement de fiche vente/logistique) | `commissions` |
| Dépense validée (`DepenseValideeNotification`, nouveau) | Propriétaire du véhicule, uniquement si la dépense est imputée à un `proprietaire` (catégorie VEHICULE) | `depenses` |
| Commission manquante (`CommissionManquanteNotification`) | Staff org + déclencheur — inchangé, hors préférences | — |

**Gaps documentés, volontairement hors phase 1** :
- `CommandeVente.LIVREE` (déclenché au premier encaissement, pas une action
  "livraison terminée" isolée) n'alimente aucune notification — "livraison
  terminée" est portée par la réception de transfert logistique à la place.
- Dépense imputée à `livreur`/`site`/`salarie`/`prestataire` : aucun envoi
  (scope limité à `proprietaire`, priorité produit explicite).
- Tout le contexte `prestataire` (compte connecté, `user_id`, flux
  commissions/dépenses, préférences) reste un chantier métier séparé.

Tests : `tests/Feature/Jobs/NotifierLivreursCommandeVenteJobTest.php`,
`tests/Feature/Jobs/NotifierLivreursTransfertJobTest.php`,
`tests/Feature/Notification/*` (commission générée/payée, dépense validée,
transfert réceptionné, dédoublonnage/préférences du dispatcher).

### 7.2 Audit de fiabilité initial — `notification_preferences` (26/08/2026)

Avant la phase 1 ci-dessus : **une seule** catégorie de préférence existait
(`activite`) et **un seul** job envoyait réellement une notification —
`NotifierLivreursCommandeVenteJob`, qui notifiait le livreur **et** le
propriétaire. Ce job ignorait alors totalement la préférence : désactiver
"activite" n'avait aucun effet réel. Corrigé une première fois le 26/08
(préférence enfin consultée), puis reciblé le 27/08 (propriétaire retiré des
destinataires, cf. §7.1). `CommissionPayeeNotification` était à l'époque
définie mais jamais dispatchée — également corrigé en phase 1.

`GET /v1/mobile/notifications` (lecture) continue de renvoyer **tout**
l'historique déjà généré — une préférence désactivée n'empêche que les
**futures** générations, jamais une purge rétroactive.

### 7.3 Finalisation du contrat API — avant Web Push (27/08/2026)

Objectif : que Nuxt/mobile consomment `GET /v1/mobile/notifications` comme un
contrat unique et stable, sans jamais brancher sur le nom d'une classe
`App\Notifications\*` ni sur la forme historique de son payload `data`.

**Stratégie de compatibilité** : aucune ligne de la table `notifications`
n'est réécrite. `CommandeValideeNotification` et `CommissionManquanteNotification`
(antérieures à la phase 1) stockent `commande_id`/`reference` au lieu de
`resource` — `NotificationResource::resolveResource()` synthétise
`{type:'commande_vente', id: commande_id}` à la lecture pour ces deux-là
uniquement ; les 5 classes phase 1 stockent déjà `resource`/`montant`
directement (passthrough). Une notification historique et une notification
neuve ressortent avec exactement les mêmes clés
(`tests/Feature/Api/Mobile/NotificationsControllerTest.php::test_toutes_les_classes_notification_partagent_la_meme_structure`).

**Table de mapping `type`** (interne → stable, seule liste blanche, dans
`NotificationResource::TYPE_MAP`) :

| Type interne (stocké en base) | `type` exposé |
|---|---|
| `commande_validee` | `delivery.assigned` |
| `commission_manquante` | `commission.missing` |
| `commission_generee` | `commission.generated` |
| `commission_payee` | `commission.paid` |
| `depense_validee` | `expense.validated` |
| `transfert_cree` | `transfer.created` |
| `transfert_receptionne` | `transfer.received` |

**Convention de champs retenue** : français pour les champs de domaine
(`titre`, `lu`, `montant` — comme `DepenseResource::montant` déjà en
production), anglais pour les champs techniques (`id`, `type`, `resource`,
`created_at`, `read_at`, `unread_count`) — convention réelle du projet,
délibérément préférée à un gabarit tout-anglais pour ne pas introduire une
deuxième convention concurrente sur la même API.

**Changement cassant assumé** : l'enveloppe passe de `{data:[...], unread_count}`
(plafond fixe de 50, jamais paginé) à la pagination Laravel standard
`{data:[...], links:{...}, meta:{...}, unread_count}` — `data` reste un
tableau au même endroit (compatible avec tout code lisant `response.data`
directement) ; `links`/`meta` sont de purs ajouts. Le seul retrait réel est la
clé `data` **par notification** (payload brut) — remplacée par
`montant`/`resource`, précisément ce que ce chantier visait à éliminer.

**Sécurité/isolation** : `markRead`/`markAllRead` étaient déjà scopés à
`$user->notifications()`/`$user->unreadNotifications` (jamais un autre
compte) ; `markRead` renvoie désormais explicitement 404 (au lieu d'un
`{success:true}` silencieux) quand l'ID n'appartient pas à l'appelant.

**Performance** : `unread_count` reste un `COUNT` SQL
(`unreadNotifications()->count()`), jamais un chargement PHP de la liste
complète ; `index()` utilise `paginate()` (une seule requête page + une
requête `COUNT` pour `meta.total`), aucun N+1 (une seule table, pas de
relation chargée par ligne).

**Limite connue, hors périmètre** : `notifications.created_at` est un
`timestamp` (précision à la seconde, migration Laravel par défaut du
06/06/2026) sans colonne monotone de secours — deux notifications créées
dans la même seconde n'ont pas d'ordre relatif garanti par le schéma. Le tri
"plus récent d'abord" est fiable au-delà d'une seconde d'écart (cas normal),
pas à l'intérieur d'un burst simultané. Modifier ce schéma serait un chantier
séparé (table `notifications` partagée avec le cœur Laravel), volontairement
hors périmètre ici.

**OpenAPI** : `NotificationResource`/`NotificationsIndexRequest` sont
réellement typés (retours de méthode PHP natifs + PHPDoc `array{...}`, jamais
une annotation Scramble artificielle) — `docs/openapi/client.json` expose un
schéma complet (`resource` en `object` typé, pas un `array`/`object` dégradé)
pour les 3 routes.

**Confirmation** : le frontend peut consommer `GET /v1/mobile/notifications`
comme un contrat unique, sans connaître les classes `Notification` Laravel.

### 7.4 Web Push PWA — 3ᵉ canal (2026-08-28)

Architecture (cf. rapport dédié) : `NotificationDispatcher` reste le seul
point d'entrée (aucun changement de destinataires) ; le fan-out Expo + Web
Push est désormais **asynchrone**, via `DispatchPushNotificationsJob`
(`ShouldQueue`) — un événement métier (paiement, validation...) n'attend plus
les appels réseau vers Expo/les fournisseurs Web Push. Bibliothèque :
`minishlink/web-push` v11 (VAPID natif, protocole/chiffrement jamais
réimplémenté ici), client PSR-18 = Guzzle explicite (déjà dépendance Laravel).

**Web Push suit exactement la couverture Expo actuelle** : seuls 2 événements
sur 7 fournissent un payload push aujourd'hui (commande validée, transfert
créé) — les 5 autres (commission générée/payée, dépense validée, transfert
réceptionné) restent `database`-only, ce chantier n'a pas élargi cette
couverture (pas de redéfinition des destinataires/canaux, hors périmètre).

#### `GET /v1/mobile/web-push/vapid-public-key`

```json
{ "public_key": "BN...xyz" }
```

`null` si l'installation n'a pas encore généré de clés VAPID — le canal Web
Push est alors simplement indisponible (database + Expo continuent de
fonctionner normalement).

#### `POST /v1/mobile/web-push/subscriptions`

```json
// Requête — forme standard `PushSubscription.toJSON()`
{
  "endpoint": "https://fcm.googleapis.com/fcm/send/...",
  "keys": { "p256dh": "...", "auth": "..." }
}
// Réponse
{ "success": true }
```

Le serveur associe **toujours** l'abonnement à `$request->user()` — jamais à
un `user_id` envoyé par le client. Idempotent : upsert par `endpoint` (unique
globalement, pas par compte) — un même endpoint réabonné par un autre compte
(poste partagé) lui est réassigné, jamais dupliqué. Un compte peut avoir
plusieurs abonnements (un par navigateur/appareil).

#### `DELETE /v1/mobile/web-push/subscriptions?endpoint=...`

```json
{ "success": true }
```

`endpoint` en **query string**, jamais un corps JSON (support body-on-DELETE
inégal selon clients/proxys, et non documentable proprement en OpenAPI).
Supprime uniquement l'abonnement identifié par cet `endpoint`, scopé au
compte authentifié — jamais un "delete all" (se désabonner depuis un
navigateur ne doit jamais toucher les autres appareils). Idempotent : un
endpoint déjà absent, ou appartenant à un autre compte, renvoie le même
succès (jamais de 404 — n'expose jamais l'existence d'un abonnement pour un
tiers).

**Abonnements expirés** : si un fournisseur push répond que l'abonnement
n'existe plus (404/410 — `MessageSentReport::isSubscriptionExpired()`, natif
de la lib, jamais réinterprété nous-mêmes), la ligne est supprimée
automatiquement. Un échec temporaire (5xx, réseau) conserve l'abonnement.

**Sécurité** : `p256dh`/`auth`/`endpoint` ne sont jamais sérialisés dans une
réponse API (`WebPushSubscription::$hidden`) ni logués en clair (seul un
`subscription_id` apparaît dans les logs).

**Préférences** : inchangées — un abonnement Web Push enregistré ne
contourne jamais `notification_preferences` ; la permission navigateur
(`Notification.requestPermission()`) reste gérée côté client, le backend ne
prétend jamais pouvoir l'accorder.

---

## 8. Divers

### `POST /v1/mobile/contact`

```json
{ "message": "..." }
```

Crée un message de contact + tentative d'envoi email (silencieuse en cas
d'échec, ne bloque jamais la réponse). Pas de garde de rôle.

### `GET /v1/search/global?q=...&limit=5&categories[]=vehicules`

Recherche transverse (clients, commandes, factures, véhicules, propriétaires)
— filtrée par permissions/rôle en interne, jamais par le frontend :

```json
{
  "query": "sidibé", "took_ms": 42,
  "results": {
    "vehicules": { "label": "Véhicules", "total": 1, "items": [{ "id": "...", "title": "MADAME SIDIBE", "subtitle": "IV029" }] },
    "proprietaires": { "label": "Propriétaires", "total": 5, "items": [{ "id": "...", "title": "Alpha Macka SIDIBE", "subtitle": "+224626641466" }] }
  }
}
```

`results` est un objet indexé par catégorie (jamais un tableau) — une
catégorie absente = pas d'accès, pas "0 résultat". Chaque `item` a toujours
exactement `{id, title, subtitle}`, quelle que soit la catégorie. Accessible à
tout compte authentifié (pas de garde de rôle métier ici, filtrage par
permission Laravel interne à chaque fournisseur de recherche).

### `GET /api/vehicules/{vehiculeId}/photo`

**Publique, sans authentification.** Retourne l'image si elle existe, `404`
sinon. Accessible à quiconque connaît l'ID (ULID difficile à deviner, mais
aucune vérification de propriété).

---

## 9. Ce qui n'existe PAS (gaps confirmés, pas de suppositions)

| Besoin | État |
|---|---|
| "Mes commandes" pour un rôle `client` pur (achats, historique) | **Fait** (26/08/2026) — `GET /v1/mobile/commandes/mine` + `/commandes/{id}`, §6 |
| Dépenses consolidées (tous véhicules) | **Fait** (26/08/2026) — `GET /v1/mobile/depenses/mine`, §4 |
| Historique complet livraisons pour un proprietaire | **Fait** (26/08/2026) — `GET /v1/mobile/activite`, §5 |
| Dashboard financier API (net à payer, reste à payer...) | **Fait** (26/08/2026) — `GET /v1/mobile/dashboard`, §5 |
| Propositions de véhicule (créer/lister) | **Fait** (26/08/2026) — `POST`/`GET /v1/mobile/propositions-vehicules`, §4 |
| Écriture sur le profil au-delà de la localisation | **Inexistant** — nom/téléphone/email/raison sociale restent backoffice-only |
| Champ "quartier" séparé d'`adresse` | **Inexistant** en base |
| Statut véhicule "Entretien"/maintenance | **Inexistant** en base — seul `is_active` |
| Identifiant légal type SIRET | **Inexistant** — absent du modèle ELM, ne pas l'inventer |

---

## 10. Incohérence de nommage assumée

Les routes mélangent volontairement plusieurs styles historiques :
`/api/auth/*`, `/v1/mobile/*`, `/gains/mine`, `/livraisons/en-cours` (sans
préfixe). C'est un état de fait confirmé, pas une erreur de lecture — ne pas
essayer de "corriger" les URLs côté frontend en devinant un pattern cohérent
qui n'existe pas. Toute harmonisation future se fera côté backend sans casser
les URLs existantes (déjà utilisées par l'app mobile en production).
