# PWA — comment ça marche aujourd'hui

## Vue d'ensemble

L'application est installable (téléphone Android, ordinateur Chrome/Edge, ajout à
l'écran d'accueil iPhone/iPad) mais **reste entièrement en ligne pour tout ce qui
est métier**. Il n'y a aucun mode hors-ligne fonctionnel, aucune file d'attente
d'opérations, aucune synchronisation différée. Le service worker n'a qu'un rôle :
satisfaire le critère technique d'installabilité des navigateurs, en mettant en
cache uniquement une liste blanche explicite de fichiers statiques publics.

**Fichiers clés :**

| Fichier | Rôle |
|---|---|
| [public/manifest.json](../public/manifest.json) | Manifest web (nom, icônes, `display: standalone`, `start_url`/`scope`) |
| [public/sw.js](../public/sw.js) | Service worker minimal — install/activate/fetch, liste blanche stricte |
| [public/icons/](../public/icons/) | Icônes PNG 192×192, 512×512 (`any`) et 512×512 (`maskable`), dérivées du logo existant (`resources/js/components/AppLogoIcon.vue` / `public/favicon-dark.svg`) |
| [resources/views/app.blade.php](../resources/views/app.blade.php) | `<link rel="manifest">` + `<meta name="theme-color">` |
| [resources/js/app.ts](../resources/js/app.ts) | Enregistrement conditionnel du service worker (jamais en dev/HMR) |
| [vite.config.ts](../vite.config.ts) | Constantes `__PWA_ENABLED__` / `__PWA_BUILD_DIR__` injectées à la compilation |
| [public/.htaccess](../public/.htaccess) | Force `Cache-Control: no-cache` sur `sw.js` (le CDN de production applique sinon un cache de 7 jours par défaut) |
| [tests/e2e-pwa/pwa.spec.ts](../tests/e2e-pwa/pwa.spec.ts) | Tests dédiés — dans un dossier séparé de `tests/e2e/`, jamais ramassés par la suite E2E par défaut (voir plus bas) |
| [playwright.pwa.config.ts](../playwright.pwa.config.ts) | Config Playwright dédiée à ce fichier (`testDir` séparé), réutilise `playwright.config.ts` |
| [resources/js/config/pwaInstall.ts](../resources/js/config/pwaInstall.ts) | Logique pure de détection (iOS, standalone, téléphone vs tablette) et résolution d'état de la proposition d'installation |
| [resources/js/composables/usePwaInstall.ts](../resources/js/composables/usePwaInstall.ts) | Pont vers les API navigateur réelles (`beforeinstallprompt`, `appinstalled`, sessionStorage) |
| [resources/js/components/PwaInstallPrompt.vue](../resources/js/components/PwaInstallPrompt.vue) | Carte non bloquante "Installer ELM" (téléphone uniquement) + instructions iOS — cf. §3bis |

---

## 1. Ce que le service worker ne fait JAMAIS

Règle de conception, non négociable pour cette V1 :

- Aucune navigation / page HTML n'est interceptée (`request.mode === 'navigate'`
  sort immédiatement du handler `fetch`).
- Aucune réponse Inertia (`X-Inertia: true`), aucun appel `/api/*`, aucune donnée
  métier (commandes, stocks, ventes, paiements, livraisons) n'est mise en cache.
- Aucune stratégie `NetworkFirst` / `StaleWhileRevalidate` sur une route
  applicative — soit une requête est dans la liste blanche stricte (assets Vite
  hashés du build courant + icônes + manifest), soit elle continue vers le
  réseau exactement comme en l'absence de service worker.
- Aucune écriture en cache en dehors de l'étape `install` : pas de mise en cache
  « à la volée » d'une réponse rencontrée en cours de route.
- Aucun fallback HTML pour une route privée en cas de coupure réseau : une
  panne réseau produit l'échec réseau normal du navigateur, jamais une page de
  remplacement locale.

## 2. Ce qui est précaché, et comment

À l'étape `install`, le service worker :

1. Récupère le manifest **réel** du build courant (`/build/manifest.json` ou
   `/build-e2e/manifest.json` — jamais de noms de fichiers hashés maintenus à la
   main), lit l'entrée `resources/js/app.ts` et en extrait `file` + `css`.
2. Ajoute les 3 icônes PWA et `/manifest.json` (chemins fixes, sans hash).
3. Ne précache **pas** les chunks de page (`Dashboard.vue`, `Ventes/Index.vue`,
   etc., listés dans `dynamicImports` du manifest Vite) : ils restent de
   simples requêtes réseau à la demande, exactement comme aujourd'hui.
4. N'accepte en cache qu'une réponse `200` directe, même origine, non
   redirigée, dont le `Content-Type` n'est pas `text/html` (protection contre
   une page d'erreur servie par erreur avec un statut 200).

## 3. Build normal vs build E2E

`vite.config.ts` calcule `__PWA_BUILD_DIR__` (`build` ou `build-e2e`, même
logique que `buildDirectory` déjà utilisée pour Wayfinder) et `__PWA_ENABLED__` :

- **Build normal** (`npm run build`, utilisé en production) : PWA activée.
- **Build E2E par défaut** (`npm run e2e:build`) : PWA **désactivée**
  (`__PWA_ENABLED__` = `false`) — la suite fonctionnelle existante ne doit
  jamais dépendre d'un cache navigateur.
- **Build E2E avec PWA active** (`npm run e2e:build:pwa`) : à utiliser
  explicitement pour tester le service worker lui-même (voir
  [tests/e2e-pwa/pwa.spec.ts](../tests/e2e-pwa/pwa.spec.ts)).

`resources/js/app.ts` n'enregistre le service worker que si
`import.meta.env.PROD` est vrai (donc jamais avec `npm run dev`/HMR) **et** que
`__PWA_ENABLED__` est vrai. Le build (`build` vs `build-e2e`) est transmis au
fichier statique `sw.js` via la query string d'enregistrement
(`/sw.js?build=build-e2e`) puisque `sw.js` lui-même n'est pas compilé par Vite.

## 3bis. Proposition d'installation mobile (carte "Installer ELM")

**Changement du 06/10/2026** — remplace le garde-fou plein écran bloquant du
08/09/2026 (décision produit inverse : on n'oblige plus à installer). Sur
téléphone, l'interface ELM normale (connexion, back-office, espace client)
reste **entièrement utilisable dans le navigateur**. Tant qu'ELM n'est pas
installée (`display: standalone`), une carte flottante non bloquante en bas
d'écran propose un bouton **Installer**. **Tablette et desktop : jamais de
carte**, comportement web normal.

Composants : `resources/js/config/pwaInstall.ts` (logique pure, testable sans
DOM — `resources/js/config/__tests__/pwaInstall.spec.ts`),
`resources/js/composables/usePwaInstall.ts` (pont vers les API navigateur
réelles), `resources/js/components/PwaInstallPrompt.vue` (carte + dialogue
d'instructions iOS), monté à l'identique sur les 3 layouts partagés :
[AuthSimpleLayout.vue](../resources/js/layouts/auth/AuthSimpleLayout.vue)
(connexion et pages invité),
[AppSidebarLayout.vue](../resources/js/layouts/app/AppSidebarLayout.vue)
(backoffice) et [ClientLayout.vue](../resources/js/layouts/ClientLayout.vue)
(espace client) — jamais dupliqué page par page.

- **Téléphone uniquement** (`isPhoneDevice` dans `config/pwaInstall.ts`) :
  iPad toujours exclu (y compris le déguisement UA "Macintosh" d'iPadOS 13+),
  Android distingué via le token UA `Mobile` (absent sur tablette), repli sur
  un seuil de largeur d'écran pour un UA inconnu.
- **Déjà installée** (`display: standalone`, y compris `navigator.standalone`
  sur iOS) → aucune carte.
- **Android/Chrome** : `beforeinstallprompt` est intercepté
  (`preventDefault()`) ; la carte apparaît dès que l'événement est capté, et
  le clic sur **Installer** déclenche `prompt()`. Après installation
  (`appinstalled`, y compris via le menu du navigateur), la carte disparaît.
- **iOS (Safari)** : `beforeinstallprompt` ne se déclenche jamais (WebKit) —
  le bouton **Installer** ouvre un dialogue avec les 3 étapes manuelles
  (Partager → Sur l'écran d'accueil → Ajouter).
- **Navigateur sans aucun chemin d'installation** (ni `beforeinstallprompt`,
  ni iOS — ex. Firefox Android, webview in-app WhatsApp/Facebook, ou
  l'émulation mobile des DevTools Chrome) : **aucune carte**, plutôt qu'un
  bouton qui échouerait silencieusement. L'interface reste utilisable.
- **« Plus tard » / croix** : masque la carte pour la session navigateur
  (`sessionStorage`, clé `elm-pwa-install-dismissed`) ; elle est reproposée
  à la session suivante.

Limite de plateforme à connaître : un lien scanné (QR d'un ticket, lien
partagé) ouvre toujours le navigateur, jamais directement la PWA déjà
installée (iOS n'offre aucun mécanisme équivalent à une app native). Ce
n'est plus bloquant : l'utilisateur continue simplement dans le navigateur.

Tests : [tests/e2e/pwa-install-prompt.spec.ts](../tests/e2e/pwa-install-prompt.spec.ts)
(iPhone, Android avec `beforeinstallprompt` simulé, navigateur sans support,
tablette, desktop). Le chemin `beforeinstallprompt` réel (Android/Chrome)
n'est pas automatisable (critères d'installabilité réels — HTTPS/manifest/
service worker — absents en `npm run dev`) : à vérifier manuellement contre
un build réel.

## 4. Stratégie de mise à jour

Le service worker **n'appelle ni `self.skipWaiting()` ni `clients.claim()`** :
un nouvel onglet ouvert après un déploiement récupère la nouvelle version, mais
un onglet déjà ouvert reste contrôlé par l'ancienne version jusqu'à sa
fermeture complète (comportement standard des navigateurs). C'est un choix
délibéré : **aucun rechargement automatique ne peut interrompre une saisie en
cours** (formulaire de vente, de dépense...).

Conséquence assumée : un simple `F5` ne suffit pas toujours à activer une
version en attente si d'autres onglets du même site restent ouverts. Aucune UI
de notification « nouvelle version disponible » n'a été ajoutée en V1 — à
évaluer séparément si le besoin apparaît.

Le fichier `sw.js` est revérifié par le navigateur au plus toutes les 24h,
indépendamment des en-têtes HTTP (imposé par la spec Service Worker). Le CDN de
production (hCDN) applique par défaut un cache de 7 jours sur les fichiers
statiques par extension ; `.htaccess` force `Cache-Control: no-cache` sur
`sw.js` spécifiquement pour que cette revérification atteigne bien l'origine
au lieu d'un cache CDN périmé.

## 5. Procédure de désactivation / retrait

Un service worker déjà installé chez un utilisateur **survit à la suppression
du fichier côté serveur** — ne jamais se contenter de supprimer `public/sw.js`.

1. Remplacer le contenu de `public/sw.js` par un « kill switch » qui se
   désinstalle lui-même et vide uniquement les caches `elm-pwa-*` :

   ```js
   self.addEventListener('install', () => self.skipWaiting());
   self.addEventListener('activate', (event) => {
       event.waitUntil(
           (async () => {
               const names = await caches.keys();
               await Promise.all(
                   names
                       .filter((n) => n.startsWith('elm-pwa-'))
                       .map((n) => caches.delete(n)),
               );
               await self.registration.unregister();
               const clientsList = await self.clients.matchAll({ type: 'window' });
               for (const client of clientsList) client.navigate(client.url);
           })(),
       );
   });
   ```

   Ici, `skipWaiting()`/`clients.claim()` implicite via `unregister()` +
   `navigate()` sont acceptables et voulus : c'est justement la procédure de
   sortie, pas un cycle de mise à jour normal — le rechargement provoqué est
   le but recherché, pas un effet de bord à éviter.
2. Ne supprimer **que** les caches préfixés `elm-pwa-` (`caches.keys()` peut
   contenir des entrées d'autres origines/usages futurs — ne jamais faire un
   `caches.delete()` générique sur toute l'origine).
3. Laisser ce kill switch en production **1 à 2 semaines** avant de retirer la
   balise `<link rel="manifest">` et l'enregistrement dans `app.ts` : un
   appareil resté longtemps hors ligne ne reçoit la mise à jour qu'à sa
   prochaine requête réseau vers `/sw.js` — aucun délai fixe ne garantit que
   100 % des appareils l'ont reçue, d'où la marge large plutôt qu'un retrait
   immédiat.
4. Retirer ensuite le manifest, l'enregistrement JS, `public/sw.js`,
   `public/icons/`, la ligne `.htaccess` dédiée à `sw.js`, et ce document.

## 6. Limites connues par plateforme

- **iPhone/iPad (Safari)** : pas de `beforeinstallprompt` — installation
  manuelle via *Partager → Sur l'écran d'accueil*. Cache/service worker soumis
  à l'ITP (purge après ~7 jours d'inactivité).
- **Firefox desktop** : pas d'installation PWA native.
- **Multi-comptes sur un même appareil** : le service worker ne mettant
  jamais en cache de contenu authentifié/Inertia/API, ce risque est neutralisé
  par construction plutôt que par un contrôle explicite au runtime.

## 7. Tests

[tests/e2e-pwa/pwa.spec.ts](../tests/e2e-pwa/pwa.spec.ts) vérifie : validité du
manifest et accessibilité des icônes, enregistrement effectif du service
worker, contenu de Cache Storage limité à la liste blanche, non-interception
des navigations et des requêtes Inertia.

**Incident CI du 06/09/2026** : ce fichier vivait initialement dans
`tests/e2e/` (le `testDir` scanné par défaut par `npx playwright test`). La
documentation affirmait qu'il "ne tourne pas dans la suite E2E par défaut",
mais rien ne l'empêchait réellement d'être ramassé par les jobs `E2E full`/
`E2E smoke` (`npx playwright test --shard=X/4`, aucun argument) — qui buildent
avec `npm run e2e:build` (PWA désactivée). Résultat observé en CI : échecs
intermittents plutôt qu'un skip propre. Corrigé en déplaçant le fichier dans
`tests/e2e-pwa/` (hors du `testDir` par défaut) avec sa propre config
[playwright.pwa.config.ts](../playwright.pwa.config.ts). Un `testIgnore` sur
`playwright.config.ts` avait été envisagé d'abord puis écarté : vérifié qu'il
bloque aussi bien la collecte par défaut qu'un chemin de fichier passé
explicitement en argument — ce qui aurait rendu la commande ci-dessous
impossible à exécuter.

L'exécuter isolément (ces tests se connectent eux-mêmes, `E2E_SKIP_GLOBAL_SETUP=1`
évite de rejouer le seed de commissions coûteux de `global-setup.ts`) :

```bash
npm run e2e:db:reset && npm run e2e:build:pwa && E2E_SKIP_GLOBAL_SETUP=1 npx playwright test --config=playwright.pwa.config.ts
```
