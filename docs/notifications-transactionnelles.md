# Notifications transactionnelles SMS/WhatsApp (commandes, transferts)

Moteur de règles configurable qui envoie un SMS/WhatsApp au livreur et/ou au client à des étapes
clés du cycle de vie d'une commande vente ou d'un transfert logistique — livré le 07/09/2026.
Distinct du système OTP (cf. [`communications.md`](communications.md)) : partage seulement les
fournisseurs (`SmsGateway`/`WhatsAppGateway`) et le journal `message_logs`, jamais la logique
métier (génération de code, résolution de canal OTP, fallback multi-canal).

## Événements réellement câblés — et seulement ceux-ci

| Événement | Module | Transition d'état réelle | Contrôleur (vérifié contre le frontend) | Destinataire(s) |
|---|---|---|---|---|
| `commande_confirmee` | Ventes | `BROUILLON → A_CHARGER` | `CommandeVenteController::valider()` (`PATCH /ventes/{id}/valider`, bouton "Confirmer" de `Ventes/Show.vue`) | Livreur |
| `chargement_valide` | Ventes | `CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS` | `CommandeVenteStatutController::avancer()` (`POST /ventes/{id}/statut/avancer`, `Ventes/partials/ChargementDialog.vue`) | Livreur + Client |
| `transfert_cree` | Logistique | Création avec équipe assignée | `TransfertLogistiqueController::store()` | Livreur |
| `chargement_valide` | Logistique | `CHARGEMENT → TRANSIT` | `TransfertStatutController::avancer()` (`POST /logistique/{id}/statut/avancer`, `Logistique/Show.vue::submitChargement()`) | Livreur |

**Aucun autre événement n'existe.** Notamment :
- **Pas de "client à la création"** — non demandé, non câblé.
- **Pas de "client" côté Logistique** — `App\Models\TransfertLogistique` n'a aucun `client_id`
  (mouvement inter-sites pur). `NotifierChargementValideTransfertJob` ne consulte jamais de client,
  même si une règle "client" existait par erreur en base pour ce module (testé explicitement, cf.
  section Tests).

### Piège identifié pendant l'audit (point important pour la suite)

`CommandeVenteStatutController::avancer()` peut TECHNIQUEMENT faire progresser une commande depuis
`BROUILLON` (le service `CommandeVenteService::avancerStatut()` gère génériquement toutes les
transitions) — un test de régression existant (`CommandeVenteStatutTest::
test_avancer_confirme_brouillon_en_a_charger`) poste d'ailleurs directement sur `/statut/avancer`
pour cette transition. **Vérifié contre le frontend réel** (`Ventes/Show.vue::confirmer()`) : le
bouton "Confirmer" n'appelle jamais cet endpoint, uniquement `PATCH /valider`. Le déclencheur
`commande_confirmee` reste donc exclusivement sur `CommandeVenteController::valider()`. Si un
futur appelant (API mobile, script) venait à confirmer une commande via `/statut/avancer`, aucun
SMS ne partirait — à surveiller si un tel appelant apparaît un jour.

## `MessageChannel` vs `OtpChannel`

Depuis ce chantier, `message_logs` est alimenté par deux origines (OTP et transactionnel) — le
réutiliser au travers d'un enum nommé `OtpChannel` (dont le docblock disait explicitement "canal
de transport d'un **code OTP**") serait devenu trompeur. `App\Enums\MessageChannel` (SMS/WHATSAPP)
est donc la source de vérité pour `message_logs.channel`, `CommunicationRule.channel` et tous les
contrats fournisseur transactionnels. `App\Enums\OtpChannel` (SMS/WHATSAPP/EMAIL) reste
**inchangé**, réservé à `OtpChannelResolver`/`OtpDeliveryChannel` — aucune régression sur l'OTP
(seuls les casts PHP de `MessageLog` ont changé, jamais les valeurs de colonne `sms`/`whatsapp`).

## SMS et WhatsApp — deux canaux indépendants, jamais de fallback

Contrairement à l'OTP (`OtpFallbackTarget` : un échec SMS peut retransporter le même code par
email), les notifications transactionnelles n'ont **aucun repli automatique** d'un canal vers
l'autre. Si SMS et WhatsApp sont tous deux activés pour une règle, **deux notifications
indépendantes** sont générées (deux jobs `SendTransactionalCommunicationJob`, deux lignes
`message_logs`). Un échec sur un canal n'affecte jamais l'autre.

## WhatsApp non câblé — jamais un état "activé mais ignoré"

**Aucun fournisseur WhatsApp n'existe** (`App\Services\Communications\NullWhatsAppGateway`,
`isConfigured()` toujours `false`). Trois niveaux de garde, tous vérifiés par les tests :

1. **Écran de paramétrage** (`settings/Communications.vue`) — le switch WhatsApp est visuellement
   désactivé (`CommunicationChannelToggle` avec `available=false`, icône cadenas + texte
   "Fournisseur non configuré") dès que `channel_availability.whatsapp` est faux.
2. **Backend, à l'écriture** (`CommunicationRuleController::update()`) — même si le formulaire
   envoyait `enabled: true` pour WhatsApp, la règle est forcée à `false` avant enregistrement tant
   que `WhatsAppGateway::isConfigured()` est faux (défense en profondeur, jamais confiance au
   frontend seul — cf. règle backend-source-de-vérité).
3. **Backend, à la lecture** (`CommunicationRuleResolver::isEnabled()`) — revérifie
   `isConfigured()` même pour une règle déjà `enabled=true` en base : si le fournisseur devait un
   jour être retiré après coup, aucune règle existante ne continuerait à "réussir" silencieusement.

Aucune ligne `message_logs` n'est jamais créée pour un envoi WhatsApp qui n'a pas eu lieu — le
`CommunicationRuleResolver` filtre AVANT que `TransactionalCommunicationDispatcher` ne dispatche
quoi que ce soit.

## `communication_rules` — schéma

```text
organization_id   NOT NULL (toujours une vraie organisation, contrairement à message_logs)
module             App\Enums\CommunicationModule      (ventes | logistique)
event               App\Enums\CommunicationEvent        (commande_confirmee | chargement_valide | transfert_cree)
recipient_type      App\Enums\CommunicationRecipientType (livreur | client)
client_type         App\Enums\ClientType, nullable       (obligatoire si recipient_type=client, sinon NULL)
channel             App\Enums\MessageChannel            (sms | whatsapp)
enabled             boolean, défaut false
```

Une ligne par case à cocher réelle de l'écran de paramétrage — absence de ligne = désactivé (même
convention que `produit_seuils_alerte`, cf. `docs/stock-alertes.md`).

**Contrainte d'unicité** (`organization_id, module, event, recipient_type, client_type, channel`)
— note technique : MySQL traite chaque `NULL` comme distinct dans un index UNIQUE, donc cette
contrainte protège réellement les règles **client** (client_type toujours renseigné) mais pas les
règles **livreur** (client_type toujours NULL) au niveau moteur. L'absence de doublon livreur est
garantie par le point d'écriture unique (`CommunicationRuleController::update()`, toujours un
`updateOrCreate()` jamais un insert brut), pas par l'index seul.

`App\Http\Controllers\Settings\CommunicationRuleController::VALID_RULE_KEYS` est la liste fermée
des 5 combinaisons (module, event, recipient_type) ci-dessus — toute autre combinaison (ex: "Ventes
/ commande_confirmee / client") est rejetée en 422 à l'enregistrement, jamais silencieusement
acceptée.

## Résolution des destinataires — jamais de compte `User` requis

`App\Services\Communications\TransactionalCommunicationDispatcher` résout le téléphone
**directement** sur le modèle métier (`Livreur::telephone`, `Client::telephone`) — jamais via
`App\Services\Notification\BeneficiaireUserResolver` (qui exige un compte `User` connecté et sert
uniquement au push/in-app). Un livreur ou un client sans aucun compte applicatif reçoit quand même
son SMS, tant qu'un numéro de téléphone est renseigné. Absence de téléphone = aucune notification,
jamais une exception.

## Cycle de déclenchement

```text
Événement métier (confirmation, chargement validé, création transfert)
    │
    ▼
Job existant étendu (NotifierLivreursCommandeVenteJob / NotifierLivreursTransfertJob)
  ou nouveau (NotifierChargementValideCommandeVenteJob / NotifierChargementValideTransfertJob)
    │
    ▼  TransactionalCommunicationDispatcher::notifierLivreur() / notifierClient()
    │  (résout organization_id + téléphone, ignore silencieusement l'absence de téléphone)
    │
    ▼  CommunicationRuleResolver::isEnabled() — pour CHAQUE canal (sms, whatsapp)
    │
    ▼  si activé : SendTransactionalCommunicationJob::dispatch(canal, ...)
    │
    ▼  MessageLogService::logTransactionalAttempt() → pending
    │
    ▼  SmsGateway::send() / WhatsAppGateway::send()
    ├── succès → markSent()  → sent,  provider_message_id
    └── échec  → markFailed() → failed, provider_status, error_code (jamais bloquant)
```

Chaque job de déclenchement (`Notifier*Job`) est lui-même `ShouldQueue`, dispatché depuis le
contrôleur — la validation de chargement/la confirmation de commande/la création de transfert ne
sont **jamais** retardées ni mises en échec par une panne du fournisseur SMS/WhatsApp
(« la communication est une conséquence de l'action métier, jamais une condition de sa réussite »).

## Contenu des messages

Textes en dur dans `App\Services\Communications\TransactionalMessageBuilder` pour cette étape —
un éditeur de templates (`communication_templates`) est explicitement hors périmètre, prévu pour
une itération future si le besoin se confirme.

## Permissions

- `communications.read` (déjà créée en P1) — consulter le monitoring (`message_logs`).
- `communications.manage` (nouvelle) — consulter/modifier les règles (`communication_rules`),
  écran Paramètres → Communications. Assignée par défaut à `admin_entreprise` uniquement
  (`RolesAndPermissionsSeeder`) — `communications.read` reste assignée à `admin_entreprise` +
  `manager`. Les deux permissions sont indépendantes : `communications.read` seul ne donne jamais
  accès à l'écran de paramétrage.

## Écran Paramètres → Communications

`GET/PUT /settings/communications` (`CommunicationRuleController`) — une section par module
(Ventes, Logistique), une sous-section par événement, des cases à cocher SMS/WhatsApp
indépendantes par destinataire (Livreur, ou Client détaillé par type Externe/Revendeur/
Distributeur/Grossiste pour Ventes → Chargement validé uniquement). Pas de section "Client" pour
Logistique — cf. section "Événements réellement câblés" ci-dessus.

## Tests

- [`CommunicationRuleResolverTest`](../tests/Unit/CommunicationRuleResolverTest.php) — règle
  activée/désactivée/absente, isolation par organisation, indépendance des types de client,
  indépendance SMS/WhatsApp, clamp WhatsApp non configuré.
- [`SendTransactionalCommunicationJobTest`](../tests/Unit/SendTransactionalCommunicationJobTest.php)
  — cycle `pending → sent/failed` par canal, `messageable` correctement renseigné, SMS+WhatsApp
  indépendants, aucun contenu de message journalisé.
- [`NotifierChargementValideCommandeVenteJobTest`](../tests/Feature/Jobs/NotifierChargementValideCommandeVenteJobTest.php)
  — livreur ET client (par type), livreur/client sans compte `User`, absence de téléphone, absence
  de client sur la commande, les deux règles activées notifient indépendamment.
- [`NotifierChargementValideTransfertJobTest`](../tests/Feature/Jobs/NotifierChargementValideTransfertJobTest.php)
  — livreur uniquement, une règle "client" insérée par erreur pour Logistique ne notifie jamais
  personne.
- [`NotifierLivreursCommandeVenteJobTest`](../tests/Feature/Jobs/NotifierLivreursCommandeVenteJobTest.php)
  / [`NotifierLivreursTransfertJobTest`](../tests/Feature/Jobs/NotifierLivreursTransfertJobTest.php)
  — extension des jobs existants (push in-app inchangé + nouveau SMS transactionnel).
- [`CommunicationRuleControllerTest`](../tests/Feature/Settings/CommunicationRuleControllerTest.php)
  — permission dédiée (`communications.read` seul insuffisant), isolation par organisation, clamp
  WhatsApp à l'écriture, rejet d'une combinaison inconnue, idempotence de l'upsert.
- [`CommandeVenteCommunicationTest`](../tests/Feature/CommandeVenteCommunicationTest.php) —
  bout-en-bout sur les VRAIES routes HTTP (confirmation + validation de chargement), y compris la
  garantie qu'une panne fournisseur ne bloque jamais la réponse HTTP.

## Hors périmètre de ce chantier

- Éditeur de templates de messages.
- Fournisseur WhatsApp réel.
- Notification "client à la création" (non demandée).
- Destinataire "site" pour un transfert logistique (non demandé — seul le livreur peut être notifié
  aujourd'hui pour ce module).
