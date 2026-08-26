# Contrat API — Espace client (Nuxt / mobile)

Documentation complète, vérifiée directement dans le code de `elm-monolithe`
(pas de suppositions), de tout ce qu'un frontend "espace client" (Nuxt, PWA,
app mobile) peut consommer aujourd'hui. Couvre l'authentification, le profil,
les véhicules, les gains, les dépenses, les livraisons, les notifications et la
recherche — avec, pour chaque section, ce qui existe réellement et ce qui n'existe
pas encore.

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

Les routes ci-dessous (§3 à §6, sauf mention contraire) exigent, **en plus**
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

⚠️ **Pas d'endpoint "mes dépenses" consolidé** (tous véhicules) — seulement par
véhicule. Pour une page "Mes dépenses" globale, il faut soit appeler cet
endpoint pour chaque véhicule (N+1), soit demander la construction d'un
endpoint consolidé côté backend (pas encore fait).

---

## 5. Gains et livraisons

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

⚠️ **N'inclut PAS les commissions logistiques** (`CommissionLogistiquePart`) —
uniquement les commissions de vente (`CommissionEnveloppePart`). L'espace
client Inertia existant (`ClientDashboardController::calculateEarnings()`)
combine déjà les deux + les dépenses en un seul "solde" (`total_earned,
total_paid, frais_depenses_total, balance`) — ce moteur de calcul est
**prouvé et testé**, mais n'est pas encore exposé via cette API. Un futur
`GET /api/client/dashboard` devrait le réutiliser (jamais dupliquer la
logique financière), pas encore construit.

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

### `GET /v1/mobile/livraisons/scan/{reference}`

Résout une référence scannée (QR) — préfixe `CMD-` = commande de vente, `TR-` =
transfert logistique. `404` si référence non reconnue ou introuvable. Pas de
garde de rôle explicite (`auth:sanctum` seul) — la donnée retournée dépend
uniquement de l'existence de la référence, pas d'un filtre par propriétaire.

---

## 6. Notifications

### `GET /v1/mobile/notifications`

```json
{
  "data": [
    { "id": "...", "type": "livraison_terminee", "titre": "Livraison CMD-2841 terminée",
      "message": "12 packs livrés aujourd'hui", "data": {}, "lu": false,
      "created_at": "2026-08-26T10:00:00.000000Z" }
  ],
  "unread_count": 3
}
```

Système générique Laravel (`Notifiable`/`DatabaseNotifications`) — **aucune
garde de rôle** sur ces 3 routes (`auth:sanctum` seul suffit, y compris pour un
compte staff pur). `type`/`titre`/`message` viennent du payload `data` stocké à
la création de la notification (structure libre selon le type de notif émis
côté backend).

### `POST /v1/mobile/notifications/mark-all-read`

Marque toutes les notifications non lues comme lues.

### `POST /v1/mobile/notifications/{id}/read`

Marque une notification précise comme lue (no-op si déjà lue ou introuvable
pour ce compte).

### `POST /v1/mobile/push-token`

```json
{ "expo_push_token": "ExponentPushToken[...]" }
```

Enregistre le jeton technique Expo pour le push — distinct de la préférence
métier `notification_preferences` (§3). Écrit sur `users.expo_push_token`.

---

## 7. Divers

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

## 8. Ce qui n'existe PAS (gaps confirmés, pas de suppositions)

| Besoin | État |
|---|---|
| "Mes commandes" pour un rôle `client` pur (achats, historique) | **Inexistant** — tous les endpoints `Client\*` sont orientés proprietaire/livreur |
| Dépenses consolidées (tous véhicules) | **Inexistant** — seulement par véhicule (§4) |
| Historique complet livraisons pour un proprietaire | **Inexistant** — `/livraisons/en-cours` ne couvre que l'en-cours |
| Dashboard financier API (net à payer, reste à payer...) | **Inexistant** — moteur de calcul déjà prouvé côté Inertia, pas encore exposé en API |
| Propositions de véhicule (créer/lister) | **Inexistant côté API** — existe seulement en Inertia (`ClientDashboardController::storeVehicleProposal`) |
| Écriture sur le profil au-delà de la localisation | **Inexistant** — nom/téléphone/email/raison sociale restent backoffice-only |
| Champ "quartier" séparé d'`adresse` | **Inexistant** en base |
| Statut véhicule "Entretien"/maintenance | **Inexistant** en base — seul `is_active` |
| Identifiant légal type SIRET | **Inexistant** — absent du modèle ELM, ne pas l'inventer |

---

## 9. Incohérence de nommage assumée

Les routes mélangent volontairement plusieurs styles historiques :
`/api/auth/*`, `/v1/mobile/*`, `/gains/mine`, `/livraisons/en-cours` (sans
préfixe). C'est un état de fait confirmé, pas une erreur de lecture — ne pas
essayer de "corriger" les URLs côté frontend en devinant un pattern cohérent
qui n'existe pas. Toute harmonisation future se fera côté backend sans casser
les URLs existantes (déjà utilisées par l'app mobile en production).
