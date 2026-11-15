# Configuration (`.env`)

> **Statut** : en place · **Dernière vérification** : 15/11/2026, d'après `config/*.php`, les
> appels `env()` du code et les fichiers `.env.*.example`
>
> Aucune valeur réelle de secret ne figure ici. Les valeurs données sont des exemples ou les
> valeurs par défaut du code.

## Les fichiers

| Fichier | Versionné | Rôle |
|---|---|---|
| `.env` | non | Configuration de l'instance (poste de développement ou serveur) |
| `.env.example` | oui | Modèle pour le développement local ; copié en CI pour les contrôles |
| `.env.production.example` | oui | Modèle pour la production |
| `.env.preprod.example` | oui | Modèle pour la recette / formation |
| `.env.testing` | oui | PHPUnit (SQLite en mémoire) ; valeurs de test uniquement |
| `.env.e2e` | oui | Playwright (`--env=e2e`, base `elm_monolithe_e2e`) ; valeurs de test uniquement |

> ⚠️ Le dépôt est **public** : ne jamais mettre une valeur réelle dans un fichier versionné, ni
> copier une valeur de `.env.testing` / `.env.e2e` (connue de tous) dans un `.env` de serveur.

> ⚠️ **Modèles de serveur incomplets** (constat du 15/11/2026) : `.env.production.example` ne contient
> ni `APP_INSTALL_TOKEN`, ni `APP_DEPLOYMENT_MODE`, ni `VITRINE_SERVICE_TOKEN`, ni les variables
> Nimba SMS et Web Push, et son `APP_URL` est une ancienne adresse. Partir de ce document plutôt que
> du modèle pour un nouveau serveur.

### Configuration en cache

Le déploiement lance `php artisan optimize` : la configuration est mise en cache et **le fichier
`.env` n'est plus relu**. Conséquences :

- après une modification du `.env` sur un serveur, relancer `php artisan optimize:clear` puis
  `php artisan optimize` ;
- les variables lues directement dans le code, hors de `config/` (signalées **hors config**
  ci-dessous), reçoivent leur **valeur par défaut** quand la configuration est en cache, quelle que
  soit la valeur du `.env`.

Légende : **Oblig.** = obligatoire en production ; **Prod / Formation / Local** = valeur conseillée
ou constatée dans les modèles.

## Application

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `APP_NAME` | Nom de l'application (courriels, titre) | oui | texte | — |
| `APP_ENV` | Environnement Laravel | oui | `local`, `production`, `testing`, `e2e` | **`production` sur les deux serveurs** (production et formation) : c'est lui qui bloque `migrate:fresh` et les commandes destructives |
| `APP_KEY` | Clé de chiffrement (sessions, données chiffrées) | oui | `base64:…`, générée par `php artisan key:generate` | Propre à chaque instance ; ne jamais la changer sur une instance en service |
| `APP_PREVIOUS_KEYS` | Anciennes clés acceptées en lecture après une rotation | non | liste séparée par des virgules | — |
| `APP_DEBUG` | Affiche le détail des erreurs | oui | `true` / `false` | **`false` sur un serveur** ; `true` en local |
| `APP_URL` | Adresse publique ; utilisée par les liens des courriels et figée dans le front à la compilation (Wayfinder) | oui | `https://…` sans `/` final | Local : adresse de `php artisan serve` ; E2E : `http://127.0.0.1:8080` |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | Langue | non | `fr` | — |
| `APP_FAKER_LOCALE` | Langue des données fictives des seeders | non | `fr_FR` | Développement |
| `APP_MAINTENANCE_DRIVER` | Stockage du mode maintenance | non | `file` | — |
| `APP_VERSION` | Version affichée | non | `1.3.188` | **Hors config** : ignorée en cache ; la version vient du fichier `VERSION` écrit par le déploiement, sinon de `git describe` |
| `APP_RELEASED_AT` | Date de version affichée | non | date | **Hors config** ; même logique avec le fichier `RELEASED_AT` |
| `PWA_NAME`, `PWA_SHORT_NAME` | Nom de l'application installée (manifeste) | non | texte ; défaut `ELM` | Différent par environnement ([pwa.md](../pwa.md)) |
| `THEME_DEFAULT_PRESET`, `THEME_DEFAULT_PRIMARY`, `THEME_DEFAULT_SURFACE` | Thème par défaut | non | voir [theming.md](../theming.md) | — |
| `THEME_ALLOWED_PRESETS`, `THEME_ALLOWED_PRIMARIES`, `THEME_ALLOWED_SURFACES` | Thèmes autorisés | non | liste ; vide = tout le catalogue | — |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | Journaux | non | `stack`, `single`, `debug` | Production : `LOG_LEVEL=warning` |

## Base de données

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `DB_CONNECTION` | Moteur | oui | `mysql` | **Défaut du code et de `.env.example` : `sqlite`.** Développement et serveurs : `mysql`. Tests : `sqlite` |
| `DB_HOST`, `DB_PORT` | Serveur MySQL | oui | `127.0.0.1`, `3306` | — |
| `DB_DATABASE` | Nom de la base | oui | texte | Local : au choix ; E2E : `elm_monolithe_e2e` ; tests : `:memory:` |
| `DB_USERNAME`, `DB_PASSWORD` | Identifiants | oui | secret | Laragon : `root` sans mot de passe |
| `DB_CHARSET`, `DB_COLLATION` | Encodage | non | `utf8mb4`, `utf8mb4_unicode_ci` | — |
| `DB_TESTING_*` | Base MySQL des tests de concurrence (`*ConcurrenceTest.php`) | non | défaut : base `elm_monolithe_concurrency_test` sur le MySQL de `DB_HOST` | Tests seulement |

Garde-fou : quand `APP_ENV=testing`, `bootstrap/app.php` refuse de démarrer si la base n'est pas
SQLite en mémoire ([tests.md](../developpement/tests.md)).

## Authentification et sécurité

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `BCRYPT_ROUNDS` | Coût du hachage des mots de passe | non | `12` | Tests : `4` (rapidité) |
| `SANCTUM_EXPIRATION_MINUTES` | Durée de vie des jetons d'API (applications mobiles) | non | minutes ; défaut `129600` (90 jours) | — |
| `SANCTUM_TOKEN_PREFIX` | Préfixe des jetons | non | vide | — |
| `OTP_FIXED_CODE` | Code OTP fixe, pour des tests déterministes | **interdit en production** | 6 chiffres | Tests et E2E seulement. ⚠️ Le code l'utilise dès qu'il est défini, sans vérifier l'environnement : il **ne doit pas exister** dans le `.env` d'un serveur |
| `WEB_REGISTRATION_ENABLED` | Ouvre l'inscription publique sur le web | non | défaut `true` | **Hors config** : en cache, vaut toujours `true` |
| `API_DOCS_ENABLED` | Ouvre `/docs/api` et `/docs/vitrine` | non | défaut `false` (fermé) | Local et formation : `true` ; production : `false` |
| `SESSION_SECURE_COOKIE` | Cookie de session en HTTPS seulement | non | `true` / `false` | Conseillé `true` derrière HTTPS (non défini dans les modèles) |

Les proxys de confiance (adresses Cloudflare) sont fixés dans `config/cloudflare.php`, sans
variable.

## Organisations et déploiement

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `APP_DEPLOYMENT_MODE` | `on_premise` : `/install` se ferme après la première installation. `saas` : `/install` reste ouvert pour créer d'autres organisations | non | défaut `on_premise` | `.env.example` : `saas` |
| `APP_INSTALL_TOKEN` | Clé exigée par l'assistant `/install` | **oui en `saas`** (sinon `/install` répond 500) ; conseillée en `on_premise` | secret | Absente de `.env.production.example` : à ajouter |

Voir [hostinger.md](../deploiement/hostinger.md#premier-déploiement-dun-site-une-seule-fois).

## Cache et sessions

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `CACHE_STORE` | Stockage du cache | non | défaut `database` | Modèles serveur : `file` ; tests : `array` |
| `CACHE_PREFIX`, `CACHE_PATH_SUFFIX` | Isolation du cache | non | `CACHE_PATH_SUFFIX=-e2e` en E2E | — |
| `SESSION_DRIVER` | Stockage des sessions | non | défaut `database` | Modèles serveur : `file` |
| `SESSION_LIFETIME` | Durée de session | non | minutes ; `120` | — |
| `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | Cookie de session | non | `false`, `/`, `null` | — |
| `PENNANT_STORE` | Stockage des modules activables | non | `database` | — |
| `REDIS_*`, `MEMCACHED_*` | Serveurs de cache | non | — | **Non utilisés à ce jour** (aucun modèle ne les active) |

## Files d'attente

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `QUEUE_CONNECTION` | Exécution des 9 jobs en file (notifications push, SMS OTP, communications transactionnelles, déblocage des commissions…) | non | défaut `database` | **`sync`** dans les modèles serveur (exécution pendant la requête, aucun worker requis). Avec `database`, un worker est indispensable : `php artisan queue:listen` en local (lancé par `composer dev`), une tâche cron sur un serveur |

> **À vérifier** sur les serveurs Hostinger : la valeur réelle de `QUEUE_CONNECTION` et, si elle
> vaut `database`, la tâche cron qui traite la file
> ([hostinger.md](../deploiement/hostinger.md#tâches-planifiées-et-files-dattente)).

## Messagerie et SMS

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `MAIL_MAILER` | Envoi des courriels | oui | `smtp` | Local : `log` (courriels écrits dans `storage/logs`) ; tests : `array` |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME` | Serveur SMTP | oui en `smtp` | ex. port `587` | — |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Identifiants SMTP | oui en `smtp` | secret | — |
| `MAIL_TIMEOUT` | Délai d'attente SMTP | non | secondes ; `10` | Les courriels partent pendant la requête : un SMTP lent ralentit l'écran |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Expéditeur | oui | adresse, texte | — |
| `CONTACT_MAIL_TO` | Destinataire des messages du formulaire de contact | non | adresse | — |
| `NIMBA_SMS_SERVICE_ID`, `NIMBA_SMS_SECRET_TOKEN` | Compte Nimba SMS (envoi des codes OTP par SMS) | non | secret | Absents = canal SMS indisponible, le courriel prend le relais |
| `NIMBA_SMS_SENDER_NAME` | Nom d'expéditeur des SMS | non | validé dans le tableau de bord Nimba, sensible à la casse | — |

`POSTMARK_*`, `RESEND_API_KEY`, `AWS_*` (SES) et `SLACK_*` sont prévus par Laravel mais **non
utilisés** à ce jour.

## Stockage

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `FILESYSTEM_DISK` | Disque par défaut | non | `local` | Les fichiers publics sont dans `storage/app/public`, exposés par `php artisan storage:link` |
| `AWS_*` | Stockage S3 | non | — | **Non utilisé** à ce jour |

## Notifications

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `WEB_PUSH_VAPID_PUBLIC_KEY` | Clé publique des notifications Web Push (front Nuxt) | non | clé VAPID, une paire par environnement | Absente = Web Push désactivé, les autres canaux continuent |
| `WEB_PUSH_VAPID_PRIVATE_KEY` | Clé privée VAPID | non | **secret**, ne quitte jamais le serveur | — |
| `WEB_PUSH_VAPID_SUBJECT` | Contact exigé par le protocole | non | `mailto:…` ou `https://…` | — |
| `MOBILE_CLIENT_SCHEME` | Schéma de lien profond vers l'application mobile (page « adresse vérifiée ») | non | défaut `mobile` | **Hors config** : en cache, vaut toujours `mobile` |

Les notifications push des applications mobiles (Expo) ne demandent aucune variable.

## Services externes

| Variable | Rôle | Oblig. | Valeur / format | Selon l'environnement |
|---|---|---|---|---|
| `VITRINE_SERVICE_TOKEN` | Clé partagée avec le site vitrine pour les routes `api/public/*` (en-tête `X-Vitrine-Key`) | oui si le site vitrine est branché | secret | Absente = ces routes répondent 403 |
| `VITRINE_APP_URL` | Adresse du site vitrine (redirection d'anciens liens) | non | défaut `https://eau-la-maman.com` | — |
| `SENTRY_LARAVEL_DSN` | Suivi des erreurs Sentry | non | DSN, **secret** | Vide = Sentry désactivé ; toujours vide en local |
| `SENTRY_ENVIRONMENT` | Nom d'environnement dans Sentry, affiché aussi par les commandes de purge | non | `production`, `local`… | — |
| `SENTRY_TRACES_SAMPLE_RATE` | Part des requêtes tracées | non | `0.0` à `1.0` | Production : `0.1` |
| `API_VERSION` | Version du contrat OpenAPI | non | `1.0.0` | **Hors config** ; changée seulement lors d'une rupture de contrat |

## Front (compilation)

Lues par Vite **au moment de `npm run build`**, figées ensuite dans les fichiers compilés.

| Variable | Rôle | Valeur |
|---|---|---|
| `VITE_APP_NAME` | Nom affiché dans le front | `"${APP_NAME}"` |
| `VITE_SHOW_ENTITY_STATS_CARDS` | Affiche les cartes de statistiques des listes | `false` ; `true` dans les builds E2E |
| `VITE_HMR_HOST`, `VITE_LAN` | Serveur de développement Vite accessible depuis le réseau local | développement seulement |
| `PWA_FORCE_ENABLED` | Force la PWA dans un build E2E (`npm run e2e:build:pwa`) | tests |

## Tests

| Variable | Fichier | Rôle |
|---|---|---|
| `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` | `.env.testing` | PHPUnit ; protégé par le garde-fou de `bootstrap/app.php` |
| `APP_ENV=e2e`, `APP_URL=http://127.0.0.1:8080`, `DB_DATABASE=elm_monolithe_e2e` | `.env.e2e` | Serveur Playwright |
| `OTP_FIXED_CODE`, `BCRYPT_ROUNDS=4`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array` | les deux | Tests rapides et déterministes |
| `E2E_BASE_URL` | variable du shell | Adresse visée par Playwright ; défaut `http://127.0.0.1:8080` (`playwright.config.ts`) |

`PULSE_ENABLED`, `TELESCOPE_ENABLED`, `NIGHTWATCH_ENABLED` et `DEBUGBAR_ENABLED` figurent dans
certains fichiers mais Pulse, Telescope et Nightwatch ne sont pas installés ; `DEBUGBAR_*` règle la
barre de débogage de développement (`config/debugbar.php`).

## Variables Laravel non listées

Les autres variables lues par `config/*.php` (`AUTH_*`, `DB_URL`, `DB_SOCKET`, `LOG_*` des canaux
Slack/Papertrail, `SESSION_*` avancées, `SENTRY_*` détaillées, `DEBUGBAR_*`…) gardent leur valeur par
défaut. Les consulter dans le fichier de `config/` concerné avant de les modifier.
