# Monitoring des communications (SMS/WhatsApp) — P1

Journal de monitoring des envois SMS/WhatsApp (`message_logs`) — un écran de consultation,
**pas** un second système OTP. Livré en **P1** le 07/09/2026 : journalisation du flux réellement
existant aujourd'hui (SMS OTP via Nimba). Les webhooks de statut de livraison (P2) et un flux
WhatsApp réel restent hors périmètre tant qu'ils n'existent pas concrètement dans l'application.

## Périmètre réel — ce qui est journalisé et ce qui ne l'est pas

- **SMS OTP sortant via Nimba** — login, vérification téléphone, réinitialisation mot de passe
  (cf. [`config/otp.php`](../config/otp.php) `purpose_channels`). C'est le **seul** flux
  réellement câblé aujourd'hui.
- **WhatsApp** — `App\Enums\OtpChannel::WHATSAPP` existe déjà, mais aucun fournisseur n'est câblé
  (cf. [`OtpDestinationMasker`](../app/Services/Otp/OtpDestinationMasker.php)). Rien n'est simulé :
  le jour où un fournisseur WhatsApp est réellement branché, il alimentera `message_logs` par le
  même mécanisme, sans changement de schéma.
- **Messages entrants** — ELM ne reçoit aujourd'hui aucun SMS/WhatsApp entrant. `direction`
  existe dans le schéma (et dans le filtre de l'écran) uniquement pour ne pas avoir à revenir sur
  la table le jour où un flux entrant sera réellement câblé — toutes les lignes P1 sont
  `outbound`.
- **SMS/notifications hors OTP** (confirmation de commande, livraison...) — n'existent pas dans
  ELM aujourd'hui. Rien n'est journalisé pour ce cas, car rien n'est envoyé.
- **`App\Services\Notification\NotificationDispatcher`** (notifications in-app + Expo push + Web
  Push) est un système **différent**, sans lien avec ce module — il ne passe par aucun canal
  SMS/WhatsApp.

## Règle de sécurité — jamais de contenu, jamais de code OTP

**COMM-001** — `message_logs` ne stocke **jamais** le corps du SMS ni le code OTP, quel que soit
le statut (y compris en erreur). Seul le `purpose` (ex: `login`) est journalisé, jamais le
message généré par `SmsOtpChannel::send()`. Cohérent avec la doctrine déjà en place côté
[`NimbaSmsGateway`](../app/Services/Sms/NimbaSmsGateway.php) (rédaction `[SMS_REDACTED]` avant
tout `Log::error`).

**COMM-002** — Le destinataire n'est jamais stocké en clair : `masked_recipient` réutilise
[`OtpDestinationMasker`](../app/Services/Otp/OtpDestinationMasker.php) (même masquage que celui
déjà renvoyé au client par `OtpLogin\RequestController`), jamais une nouvelle logique de masquage
locale.

**COMM-003** — Le Secret Token / l'en-tête `Authorization` Nimba n'apparaissent jamais dans
`message_logs` (ils n'y transitent d'ailleurs à aucun moment : `App\Contracts\SmsGateway` ne les
expose jamais au-delà de `NimbaSmsGateway`).

**COMM-006** — `CommunicationController::index()` refuse explicitement (403) tout compte dont
`organization_id` est `null`, avant même de construire la requête. Trouvé lors de l'audit sécurité
du 07/09/2026 : `organization_id` est nullable sur `users` (`nullOnDelete` si l'organisation d'un
compte est supprimée) — sans cette garde, `MessageLog::where('organization_id', $orgId)` avec
`$orgId === null` est automatiquement réécrit par Eloquent en `whereNull('organization_id')`
(comportement natif du query builder dès que la valeur passée à `where()` est `null`), ce qui
affichait toutes les lignes `message_logs` **sans** organisation (OTP de vérification téléphone
avant création de compte, cf. section "`organization_id` — résolu, jamais forcé" plus bas) à ce
compte. Jamais une fuite d'une AUTRE organisation identifiée, mais un croisement de données
orphelines non voulu — corrigé avant tout commit, cf.
[`CommunicationControllerTest`](../tests/Feature/CommunicationControllerTest.php).

## Statuts — volontairement limités à ce qui est observable en P1

**COMM-004** — `App\Enums\MessageLogStatus` : `pending` → `sent` **ou** `failed`. Pas de
`delivered` : Nimba n'a pas de webhook de statut de livraison vérifié à ce jour. `sent` signifie
uniquement « Nimba a accepté l'envoi », jamais une confirmation de livraison au destinataire —
d'où la couleur *info* (bleu, comme `envoye` ailleurs dans l'application) plutôt que *succès*
dans [`StatusDot.vue`](../resources/js/components/StatusDot.vue). Ajouter `delivered` sans avoir
vérifié l'existence réelle d'un webhook Nimba serait un statut mensonger.

**COMM-005** — `error_code` distingue une réponse fournisseur en échec (le statut HTTP Nimba,
ex: `402`) d'un échec de transport sans réponse HTTP exploitable (`transport_error` : connexion,
timeout, configuration manquante) — déterminé par le code de l'exception
(`App\Exceptions\NimbaSmsException`, cf. `NimbaSmsGateway::send()`), jamais par correspondance de
texte sur le message d'erreur.

## Cycle de journalisation

```text
OTP demandé (RequestController / VerifyController / PasswordReset\LookupController)
    │
    ▼
SmsOtpChannel::send() → dispatch SendSmsOtpJob (asynchrone, inchangé)
    │
    ▼  (dans SendSmsOtpJob::handle(), AVANT l'appel réseau)
MessageLogService::logSmsOtpAttempt() → message_log créé, status = pending
    │
    ▼  NimbaSmsGateway::send()
    ├── succès → MessageLogService::markSent()  → status = sent,  provider_message_id, sent_at
    └── échec  → MessageLogService::markFailed() → status = failed, provider_status, error_code,
                 error_message (déjà rédigé), failed_at
```

Le log est créé **dans le Job**, juste avant l'appel Nimba — pas dans `SmsOtpChannel` (qui reste
de la pure plomberie de résolution de canal, cf. son docblock) : aucune tentative d'envoi
n'échappe au monitoring, y compris un échec total du transport. Le repli vers un autre canal
(`OtpFallbackTarget`, ex: SMS → email) n'est **pas** journalisé ici : il sort du périmètre SMS/
Nimba de ce module.

`App\Services\OtpService` reste l'unique source de vérité pour la génération, l'expiration, les
tentatives, le cooldown et la validation d'un code OTP — `MessageLogService` n'y touche jamais,
il observe uniquement le transport.

## `organization_id` — résolu, jamais forcé

`organization_id` est **nullable** sur `message_logs` (contrairement à `audit_logs`) :
`MessageLogService::logSmsOtpAttempt()` le résout par recherche du numéro
(`UserAuthIdentity::resoudre()`, même mécanisme que `OtpLogin\RequestController`). Un OTP de
vérification téléphone tenté **pendant une inscription** (avant qu'un compte/organisation
n'existe) journalise donc avec `organization_id = null` plutôt qu'une valeur inventée.

**Limite P1 assumée et disclosed** : l'écran de monitoring (`CommunicationController::index()`)
filtre strictement par `organization_id` de l'utilisateur connecté — une ligne à
`organization_id = null` n'apparaît sur **aucun** écran d'organisation en P1. C'est un flux
marginal (OTP de vérification pendant une inscription non encore aboutie), documenté ici plutôt
que masqué en silence.

## Schéma — `message_logs`

Suit le patron déjà établi par [`audit_logs`](../database/migrations/0001_01_01_000046_create_audit_logs_table.php)
(ULID, `foreignUlid('organization_id')`, morph polymorphe, timestamps dédiés) — voir la migration
[`create_message_logs_table`](../database/migrations/2026_09_07_152549_create_message_logs_table.php)
pour le détail complet des colonnes.

| Champ | Rôle |
|---|---|
| `organization_id` | Nullable — cf. section précédente. |
| `channel` | `App\Enums\OtpChannel` (sms/whatsapp/email) — réutilisé, aucun nouvel enum canal. |
| `direction` | `App\Enums\MessageDirection` — `outbound` uniquement en P1. |
| `purpose` | `App\Enums\OtpPurpose` — jamais le contenu du message. |
| `provider` | `nimba` aujourd'hui — simple chaîne, un seul fournisseur réellement câblé. |
| `provider_message_id` | Renvoyé par `SmsGateway::send()` si le fournisseur en fournit un — jamais inventé. |
| `masked_recipient` | Cf. COMM-002 — jamais le numéro complet. |
| `status` | `App\Enums\MessageLogStatus` — cf. COMM-004. |
| `provider_status` / `error_code` / `error_message` | Cf. COMM-005 — `error_message` déjà rédigé en amont, jamais le message SMS ni un secret. |
| `messageable_type` / `messageable_id` | Morph nullable — reste `null` en P1 (aucune entité métier naturelle à ce niveau du flux OTP, cf. rapport). |

## Écran back-office

`GET /backoffice/communications` (`CommunicationController::index`) — liste + filtres
(`DataFilters.vue` : Statut, Canal, Sens, Destinataire, Période), statut affiché via
`StatusDot.vue`. Volontairement simple pour ce P1 : pas de KPI ni de dashboard (P3).
`hide-agence-selector` : cette donnée n'a pas de dimension site (l'OTP n'est jamais rattaché à un
site), le filtre Agence de `DataFilters.vue` ne s'applique donc pas ici.

Permission dédiée `communications.read` (`App\Support\Permissions\PermissionCatalog::STANDALONE`)
— jamais de bypass par nom de rôle. Assignée par défaut à `admin_entreprise` et `manager`
(`RolesAndPermissionsSeeder`), comme les autres écrans de consultation technique
(`imports-produits.read`, `imports-flotte.read`).

## Hors périmètre P1 — prochaines étapes

- **P2 — Webhooks Nimba** : nécessite de vérifier au préalable, contre la documentation/le compte
  Nimba réels, si un webhook de statut de livraison existe et comment il s'authentifie
  (idempotence obligatoire). Ajoutera `MessageLogStatus::DELIVERED` **seulement** à ce moment-là.
- **P3 — Dashboard** : volumes, taux d'erreur/délivrabilité, alertes de dérive.
- **WhatsApp réel** et **messages entrants** : le schéma (`channel`, `direction`) est prêt à les
  accueillir, mais rien n'est câblé tant qu'un fournisseur/flux réel n'existe pas.

## Tests

- [`MessageLogServiceTest`](../tests/Unit/MessageLogServiceTest.php) — création `pending`,
  résolution `organization_id` (trouvée / absente), `markSent`/`markFailed`, catégorisation
  `error_code`, recipient jamais en clair.
- [`SendSmsOtpJobTest`](../tests/Unit/SendSmsOtpJobTest.php) — cycle complet `pending → sent`/
  `failed` depuis le Job, `provider_message_id` uniquement en cas de succès, aucun OTP/contenu SMS
  dans le log.
- [`NimbaSmsGatewayTest`](../tests/Unit/NimbaSmsGatewayTest.php) — `message_id` retourné en cas de
  succès, code HTTP propagé comme code d'exception en cas d'échec.
- [`OtpLoginNimbaSmsTest`](../tests/Feature/Api/Auth/OtpLoginNimbaSmsTest.php) — bout-en-bout via
  le vrai contrôleur : `organization_id` résolu correctement sur un flux réel.
- [`CommunicationControllerTest`](../tests/Feature/CommunicationControllerTest.php) — permission
  refusée/accordée, isolation stricte entre organisations, filtre statut.
