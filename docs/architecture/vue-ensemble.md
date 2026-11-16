# Vue d'ensemble de l'architecture

> **Statut** : en place · **Dernière vérification** : 15/11/2026 (lecture du code et des workflows)
>
> Décrit l'application **telle qu'elle fonctionne aujourd'hui**. L'architecture de distribution
> envisagée (Docker, VPS par client) sera décrite séparément, avec ses ADR, quand elle sera engagée.

## Les briques du système

| Brique | Dépôt | Rôle |
|---|---|---|
| **Back-office** (ce dépôt) | `elm-monolithe` | Application métier : ventes, logistique, stock, trésorerie, comptabilité, commissions, RH, rapports. Expose aussi les API des applications mobiles et du site. |
| **Application mobile client / livreur** | `react-elm-client` (dossier voisin `elm-mobile-client`) | Expo (React Native), paquet Android `com.flc.eaulamaman2`. |
| **Application mobile pro** | `react-elm-pro` (dossier voisin `elm-mobile-pro`) | Expo (React Native), paquet `com.issamobile.elmpro`. |
| **Site et espace client** | `elm-vitrine-nuxt` | Nuxt 3. Appelle l'API du back-office (`NUXT_PUBLIC_API_BASE`) ; appels serveur à serveur protégés par `VITRINE_SERVICE_TOKEN`. |

Les applications mobiles reçoivent l'adresse de l'API **à la compilation** (`EXPO_PUBLIC_API_URL`
dans les profils de `eas.json`) : une application publiée ne s'adresse qu'à un seul serveur.

## Le back-office

| Couche | Technologie |
|---|---|
| Langage | PHP 8.4 (version utilisée par la CI et les déploiements ; `composer.json` indique `^8.2`) |
| Framework | Laravel 12 |
| Authentification | Fortify (web, connexion par téléphone + mot de passe), Sanctum (jetons des API) |
| Droits | Spatie Permission (rôles et permissions), catalogue unique dans `app/Support/Permissions/PermissionCatalog.php` |
| Modules activables | Laravel Pennant, `app/Features/ModuleFeature.php` (ventes, PDV, achats, logistique, dépenses, RH, comptabilité, cashback…) |
| Interface | Inertia.js 2 + Vue 3 + TypeScript, Vite, Tailwind CSS 4, PrimeVue 4 |
| Routes front typées | Laravel Wayfinder (`resources/js/actions`, `resources/js/routes`, générés) |
| Base de données | MySQL (production, développement local) ; SQLite en mémoire pour les tests PHPUnit |
| PDF | barryvdh/laravel-dompdf, modèle unique : [documents-pdf.md](../documents-pdf.md) |
| Excel | maatwebsite/excel |
| Images | Intervention Image (pilote GD) |
| Documentation d'API | Scramble ; exports dans [docs/openapi/](../openapi/) |
| Erreurs | Sentry (`sentry/sentry-laravel`) |
| Notifications | SMTP, SMS (Nimba SMS), notifications push Expo, Web Push (clés VAPID) |

### Organisation du code

| Dossier | Contenu |
|---|---|
| `app/Http/Controllers` | Contrôleurs, en majorité à action unique : [refactor-controllers-mono-action.md](../refactor-controllers-mono-action.md) |
| `app/Services` | Règles métier, regroupées par domaine (Ventes, Logistique, Tresorerie, Comptabilite, Commission, Rh, Rapports…) |
| `app/Models` | Modèles Eloquent |
| `app/Support` | Briques transverses (périodes, permissions, documents PDF…) |
| `resources/js/pages` | Pages Inertia (une par écran) |
| `resources/js/components` | Composants partagés (`StatusDot`, `DataFilters`, `ListPageActions`…) |
| `routes/web.php`, `routes/settings.php` | Back-office (préfixe `/backoffice`), onboarding, paramètres |
| `routes/api.php` | API : `v1/backoffice` (application pro), `v1/mobile`, espace client, site |
| `routes/console.php` | Tâches planifiées |
| `database/migrations` | Migrations, à n'ajouter que dans l'ordre (voir [hostinger.md](../deploiement/hostinger.md#règles-à-respecter)) |

## Principes structurants

- **Multi-organisation** : chaque donnée métier porte un `organization_id`. Aucune requête ne doit
  sortir de l'organisation de l'utilisateur.
- **Le serveur fait foi** : tarifs, droits, soldes, stock, commissions et validations financières
  sont contrôlés côté serveur ; l'interface ne fait qu'en refléter l'état.
- **Agences** : les données sont rattachées à un site (agence) ; le périmètre d'agences d'un
  utilisateur est résolu côté serveur ([sites.md](../sites.md)).
- **Mode d'installation** : `APP_DEPLOYMENT_MODE` vaut `on_premise` (par défaut) ou `saas`.
  - `on_premise` : l'assistant `/install` se ferme après la création de la première organisation
    (il redirige alors vers la connexion), et une seconde organisation est refusée.
  - `saas` : `/install` reste ouvert pour créer d'autres organisations ; `APP_INSTALL_TOKEN` est
    alors obligatoire.
  - En ligne de commande, `php artisan app:install` utilise le même service (`InstallationService`).

## Traitements en arrière-plan

| Traitement | État actuel |
|---|---|
| Tâches planifiées (`routes/console.php`) | 4 tâches : déblocage des commissions (06:00), génération des périodes (00:05), surveillance des encaissements (toutes les heures), purge des jetons Sanctum (quotidienne). Elles exigent `php artisan schedule:run` chaque minute sur le serveur. |
| Files d'attente | Quelques traitements sont mis en file (jobs). Les e-mails et notifications partent **pendant la requête** (aucun n'est en file aujourd'hui). |

## Documents liés

- Décisions d'architecture et métier : [index des ADR](../adr/README.md)
- Contrats d'API : [authentification](../api-auth-contract.md),
  [espace client](../api-espace-client-contract.md), [espace parrain](../api-espace-parrain-contract.md)
- Déploiement actuel : [hostinger.md](../deploiement/hostinger.md)
- Sommaire de la documentation : [docs/README.md](../README.md)
