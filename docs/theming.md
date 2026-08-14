# Thèmes — comment ça marche aujourd'hui

## Vue d'ensemble

Il y a **deux systèmes de thème indépendants mais couplés** :

1. **Apparence (light/dark/system)** — clair, sombre, ou suit l'OS.
2. **Thème PrimeVue** (preset + couleur primaire + couleur de surface) — Aura/Lara/Material/Nora/Starter,
   avec une palette primaire (bleu, emerald, violet...) et une palette de surface (slate, zinc, stone...).

Les deux sont pilotés côté client, persistés en `localStorage` **et** en cookie (pour le SSR/premier
rendu), et appliqués en manipulant directement des variables CSS + la classe `.dark` sur `<html>`.
Il n'y a pas de fichier de thème statique unique : tout est calculé dynamiquement au runtime.

**Fichiers clés :**

| Fichier | Rôle |
|---|---|
| [resources/js/lib/primevue-theme.ts](../resources/js/lib/primevue-theme.ts) | Palettes de couleurs, presets PrimeVue, résolution env/storage, application des variables CSS |
| [resources/js/composables/useAppearance.ts](../resources/js/composables/useAppearance.ts) | State Vue réactif (`appearance`, `primeVueTheme`, `primeVuePrimary`, `primeVueSurface`) + setters qui persistent et ré-appliquent |
| [resources/js/app.ts](../resources/js/app.ts) | Bootstrap : `initializeTheme()` avant le mount pour éviter le flash, injection du preset PrimeVue dans le plugin |
| [resources/views/app.blade.php](../resources/views/app.blade.php) | Anti-FOUC : script inline qui pose `.dark` sur `<html>` **avant** que Vue/le JS bundlé ne s'exécute |
| [app/Http/Middleware/HandleAppearance.php](../app/Http/Middleware/HandleAppearance.php) | Lit le cookie `appearance` côté serveur et le partage à la vue Blade |
| [resources/js/components/AppearanceTabs.vue](../resources/js/components/AppearanceTabs.vue) | UI du sélecteur light/dark/system (page `/settings/appearance`) |
| `.env` → `VITE_PRIMEVUE_THEME` / `VITE_PRIMEVUE_PRIMARY` / `VITE_PRIMEVUE_SURFACE` | Valeurs par défaut du thème PrimeVue si l'utilisateur n'a rien choisi |

---

## 1. Apparence (light / dark / system)

### Anti-flash (FOUC)

Le vrai point de départ n'est **pas** le JS Vue mais un `<script>` inline dans
[app.blade.php](../resources/views/app.blade.php) exécuté avant tout chargement de bundle :

1. Laravel injecte `$appearance` (partagé par `HandleAppearance` middleware, lu depuis le cookie
   `appearance`, défaut `'light'`).
2. Le script inline calcule si on doit être en dark (résout `system` via
   `window.matchMedia('(prefers-color-scheme: dark)')`) et pose `document.documentElement.classList.add('dark')`
   immédiatement — avant que Vue ne monte quoi que ce soit.
3. Un `<style>` inline fixe le `background-color` du `<html>` selon light/dark, pour éviter un flash blanc
   même avant que `app.css` soit parsé.
4. Le favicon (`/favicon.svg` vs `/favicon-dark.svg`) est aussi choisi côté serveur via `$appearance`, avec
   une correction JS pour le cas `system` (le serveur ne connaît pas la préférence OS).

### Prise de relais côté Vue

Au boot de l'app ([app.ts](../resources/js/app.ts)), `initializeTheme()` (dans `useAppearance.ts`) :
- relit `localStorage.getItem('appearance')` (source de vérité côté client, le cookie n'est là que pour
  le premier rendu serveur),
- réapplique `.dark` sur `<html>` + le favicon,
- écoute les changements de `prefers-color-scheme` (pour le mode `system` en live, sans reload).

### Changer l'apparence

Le composable `useAppearance()` expose `appearance` (ref réactive) et `updateAppearance(value)` :
- écrit dans `localStorage['appearance']`,
- écrit un cookie `appearance` (1 an, `SameSite=Lax`) pour que le prochain rendu serveur (`HandleAppearance`)
  connaisse la préférence,
- toggle `.dark` sur `<html>`,
- ré-applique les couleurs PrimeVue (le calcul des tokens `--primary`, `--background`, etc. dépend de
  `isDark`, cf. section 3).

L'UI est `AppearanceTabs.vue`, utilisé par la page `/settings/appearance`
([Appearance.vue](../resources/js/pages/settings/Appearance.vue)).

---

## 2. Thème PrimeVue (preset + couleurs)

Trois axes indépendants, chacun avec sa propre clé `localStorage` + cookie :

| Axe | Type | Valeurs | Storage key |
|---|---|---|---|
| Preset | `PrimeVueThemeName` | `aura` \| `lara` \| `material` \| `nora` \| `starter` | `primevue_theme` |
| Couleur primaire | `PrimeVuePrimaryName` | 17 couleurs Tailwind (`blue`, `emerald`, `violet`...) | `primevue_primary` |
| Couleur de surface (neutre) | `PrimeVueSurfaceName` | `zinc` \| `slate` \| `stone` \| `neutral` \| `gray` | `primevue_surface` |

`starter` est un cas spécial : c'est visuellement le preset `Aura` de PrimeVue
(`PRIMEVUE_PRESETS.starter = Aura`), mais choisir `starter` **verrouille** primaire=`blue` et
surface=`slate` (cf. `updatePrimeVueTheme` dans `useAppearance.ts` — si `value === 'starter'`, il écrase
`primeVuePrimary`/`primeVueSurface` avec les defaults). C'est le thème "maison" de l'app.

### Résolution de la valeur active (ordre de priorité)

Pour chacun des 3 axes : **`localStorage` (choix utilisateur) → sinon variable d'env `.env`
(`VITE_PRIMEVUE_*`) → sinon fallback codé en dur** (`resolvePrimeVueThemeFromEnv` /
`resolvePrimeVuePrimaryFromEnv` / `resolvePrimeVueSurfaceFromEnv` dans `primevue-theme.ts`).

`.env` actuel :
```
VITE_PRIMEVUE_THEME=starter
VITE_PRIMEVUE_PRIMARY=blue
VITE_PRIMEVUE_SURFACE=slate
```

### Application concrète

`applyPrimeVueThemePreset()` appelle `usePreset()` de `@primeuix/styled` pour changer le preset PrimeVue
globalement (structure des composants : radius, espacements, etc., propre à chaque preset).

`applyPrimeVuePrimaryColor()` / `applyPrimeVueSurfaceColor()` appellent `updatePrimaryPalette()` /
`updateSurfacePalette()` de `@primeuix/styled`, qui réinjectent les variables CSS PrimeVue
(`--p-primary-*`, `--p-surface-*`) utilisées par tous les composants PrimeVue (Button, Dialog, DataTable...).

Les 11 nuances (50→950) de chaque couleur sont des **palettes codées en dur** dans `primevue-theme.ts`
(copie exacte des couleurs Tailwind v3 — `PRIMARY_PALETTES` et `SURFACE_PALETTES`), pas générées
dynamiquement.

---

## 3. Le pont entre PrimeVue et le design system "app" (shadcn/Tailwind)

C'est la partie la moins évidente : l'app utilise **deux librairies de composants en parallèle**
(PrimeVue pour les inputs/tables/dialogs complexes, un design system maison façon shadcn-vue pour le
layout/cards/boutons simples — cf. `components.json` à la racine). Les deux doivent partager la même
teinte.

`applyAppThemeColors(primary, surface, isDark)` fait ce pont : à partir des mêmes palettes
`PRIMARY_PALETTES`/`SURFACE_PALETTES` que PrimeVue, elle calcule et pose en `style` inline sur
`document.documentElement` tout le jeu de variables CSS "shadcn" : `--background`, `--foreground`,
`--card`, `--primary`, `--border`, `--sidebar-*`, `--chart-1..5`, etc. — deux jeux de valeurs (light/dark),
chacun choisissant une nuance différente de la palette selon le rôle (ex: `--primary` = nuance `600` en
light, `400` en dark, pour rester lisible sur fond clair/sombre).

Ces tokens `--*` sont ensuite consommés par les classes Tailwind utilitaires (`bg-background`,
`text-foreground`, `border-border`...) définies dans [resources/css/app.css](../resources/css/app.css)
via `@theme inline` (Tailwind v4).

`syncAppThemeColors()` (dans `useAppearance.ts`) est appelé à chaque changement d'apparence OU de
couleur PrimeVue pour recalculer ce pont — c'est ce qui garantit que PrimeVue et le reste de l'UI
restent synchronisés.

---

## Résumé du flux complet (au chargement d'une page)

```
Requête HTTP
  → HandleAppearance middleware lit le cookie "appearance" → View::share('appearance', ...)
  → app.blade.php : script inline pose .dark sur <html> selon $appearance (avant tout JS bundlé)
  → app.ts (au mount) :
      1. initializeTheme() : relit localStorage, réapplique .dark + favicon, branche le listener OS
      2. createInertiaApp() : PrimeVue est initialisé avec le preset résolu (storage > env > défaut)
      3. applyStoredPrimeVueColors() + applyAppThemeColors() : couleurs primaire/surface PrimeVue
         ET tokens CSS shadcn appliqués en une passe
  → Utilisateur change un réglage via /settings/appearance (AppearanceTabs.vue)
      → useAppearance().updateAppearance() / updatePrimeVueTheme() / updatePrimeVuePrimary() / updatePrimeVueSurface()
      → écrit localStorage + cookie, ré-applique tout immédiatement (pas de reload)
```

## Gouvernance des thèmes par environnement — analyse de l'existant

**Contexte de cette section** : idée évoquée — un admin choisit/impose le thème par environnement
(prod = bleu chez Eau-la-maman, preprod/autres = tout sauf bleu ou une couleur proche du bleu).
Ce qui suit est une analyse de **l'état actuel du code face à ce besoin**, pas une proposition
d'implémentation (rien n'est codé, rien n'est tranché).

### 1. "Environnement" n'est pas une notion que le code modélise nulle part

Chaque environnement (prod `usine-eau-front.eu`, preprod `formation.eau-la-maman.com` à venir,
local, `.env.e2e`) est un **déploiement physiquement séparé** : sa propre base MySQL, son propre
`.env`, son propre build front. Il n'y a ni colonne `environment`, ni table, ni service qui
représente "l'environnement courant" quelque part dans `app/`.

Plus précis : `APP_ENV` **ne distingue pas** prod et preprod — il vaut `production` dans les deux
cas, **volontairement** (cf. commentaire en tête de
[.env.preprod.example](../.env.preprod.example)) pour qu'aucun `app()->environment('production')`
ne se comporte différemment entre les deux. La seule variable qui distingue preprod de prod
aujourd'hui est `SENTRY_ENVIRONMENT`, utilisée uniquement par Sentry (monitoring), lue nulle part
ailleurs dans le code applicatif. Donc un futur mécanisme "par environnement" ne peut pas
s'accrocher à `app()->environment()` sans casser cette garantie d'iso prod/preprod — il faudrait
soit une nouvelle variable dédiée (même famille que `SENTRY_ENVIRONMENT`), soit s'appuyer sur autre
chose (voir point 4).

### 2. `VITE_PRIMEVUE_*` : des constantes figées au build, pas une config runtime

`VITE_PRIMEVUE_THEME` / `_PRIMARY` / `_SURFACE` (lues via `import.meta.env.*` dans
[primevue-theme.ts](../resources/js/lib/primevue-theme.ts)) sont des variables **Vite**, donc
inlinées en dur dans le bundle JS **au moment du `npm run build`** (étape 2 du déploiement, cf.
[DEPLOY-HOSTINGER-CICD.md](../DEPLOY-HOSTINGER-CICD.md)) — pas lues dynamiquement par le serveur à
chaque requête. Chaque environnement les fige déjà à sa propre valeur par ce mécanisme naturel
(un build = un `.env` = un bundle par déploiement), mais :
- ça ne peut changer qu'en refaisant un build + déploiement (pas de bascule à chaud par un admin) ;
- aujourd'hui `.env.preprod.example` et `.env.production.example` ont **exactement les mêmes**
  valeurs (`starter` / `blue` / `slate`) — le doc en tête de fichier dit explicitement que preprod
  est iso à 100% avec prod *sauf* 2 dérogations volontaires (URL/DB/nom, `MAIL_MAILER=log`), donc
  une différence de thème serait une **3ᵉ dérogation à ajouter consciemment** à cette liste documentée.

### 3. Aucune validation ni persistance serveur du thème choisi — c'est 100% client

Le point le plus important : `updatePrimeVueTheme` / `updatePrimeVuePrimary` /
`updatePrimeVueSurface` (dans [useAppearance.ts](../resources/js/composables/useAppearance.ts))
écrivent uniquement en `localStorage` + cookie, côté navigateur. **Aucune requête serveur, aucune
table, aucune validation** — le typage TypeScript (`PrimeVuePrimaryName`, union de 17 valeurs) est
la seule "restriction", et elle est purement compile-time : n'importe qui peut poser une valeur
arbitraire dans `localStorage['primevue_primary']` via les devtools, elle sera appliquée telle
quelle par `applyPrimeVuePrimaryColor()`. Il n'y a donc **aucun point d'accroche existant** pour
imposer/restreindre une liste de couleurs autorisées — ce mécanisme est entièrement à construire,
il ne s'agit pas de brancher quelque chose sur un contrôle qui existerait déjà.

`HandleInertiaRequests::share()` (le point central qui partage des données serveur → frontend à
chaque page, cf. [HandleInertiaRequests.php](../app/Http/Middleware/HandleInertiaRequests.php))
ne transmet aujourd'hui **rien** sur le thème ni sur l'environnement.

### 4. Le pattern existant le plus proche : Module (Pennant) et Parametre — tous deux scopés *organisation*, pas *environnement*

Deux mécanismes "admin décide, c'est persisté et appliqué" existent déjà dans le code, tous deux
**scopés par organisation** (multi-tenant), pas par environnement :

- **Modules** (feature flags Laravel Pennant) : [ModuleFeature.php](../app/Features/ModuleFeature.php)
  (liste + libellés) → [ModuleService.php](../app/Services/ModuleService.php) → 
  [Settings/ModuleController.php](../app/Http/Controllers/Settings/ModuleController.php)
  (`edit`/`toggle`, gardé par la permission `parametres.update`) →
  [settings/Modules.vue](../resources/js/pages/settings/Modules.vue). Persisté en base (table
  `features` de Pennant), exposé au frontend via le prop partagé `module_flags`.
- **Parametre** ([Parametre.php](../app/Models/Parametre.php)) : table clé/valeur générique
  scopée `organization_id`, typée (string/int/bool/json/decimal), cache 1h, éditable via
  [Settings/ParametreController.php](../app/Http/Controllers/Settings/ParametreController.php)
  (même permission `parametres.update`) → [settings/Parametres.vue](../resources/js/pages/settings/Parametres.vue).

**Point non-évident** : comme chaque environnement a sa **propre base de données** entièrement
séparée (prod et preprod ne partagent aucune table), un réglage stocké dans `Parametre` (ou un
Pennant feature) **est déjà, de facto, "par environnement"** sans qu'aucun champ `environment` ne
soit nécessaire — modifier ce réglage sur formation.eau-la-maman.com (preprod) ne peut
techniquement pas toucher la ligne équivalente dans la base de prod, ce sont deux lignes dans deux
bases physiquement distinctes. C'est différent de "par organisation" au sens multi-tenant classique
(plusieurs organisations dans une même base) : ici chaque environnement n'a de toute façon qu'un
sous-ensemble d'organisations qui lui est propre.

### 5. La contrainte "pas bleu, ni une couleur proche du bleu" n'a aucun support dans les données actuelles

`PrimeVuePrimaryName` ([primevue-theme.ts](../resources/js/lib/primevue-theme.ts)) est une liste
plate de 17 noms (`zinc, emerald, green, lime, yellow, sky, blue, indigo, violet, purple, fuchsia,
pink, rose, orange, amber, teal, cyan`) — chacun avec sa palette de 11 nuances codée en dur, mais
**aucune métadonnée de famille de teinte**. Rien dans le code ne sait aujourd'hui que `sky`,
`indigo` ou `cyan` sont visuellement "proches du bleu" — c'est une notion perceptuelle absente des
données, elle n'est déductible d'aucune structure existante (il n'y a pas de valeur de teinte HSL
stockée, seulement des hex bruts par nuance). Exclure "le bleu et ses voisins" suppose donc de
définir à la main quel sous-ensemble de la liste compte comme "famille bleue" — ce n'est calculable
automatiquement à partir de rien de ce qui existe.

### 6. Ambiguïté de fond non tranchée par l'existant

Le système actuel est une **préférence par utilisateur** (chaque compte choisit sa propre couleur,
persistée dans son navigateur). "L'admin décide le thème de l'environnement" est un changement de
philosophie : est-ce que ça devient une valeur **imposée** (plus aucun choix utilisateur, ou choix
limité à une liste blanche), ou juste un **défaut par environnement** que l'utilisateur peut
toujours changer ensuite ? Les deux se recodent différemment (whitelist appliquée à la validation +
au picker UI, vs. simple valeur par défaut) et rien dans le code actuel ne penche vers l'un ou
l'autre — c'est une décision produit à prendre avant toute implémentation, pas une question
technique.

---

## Direction retenue (suite à l'échange avec Codex)

Décision produit tranchée : **le preset/couleur primaire/couleur de surface deviennent une
politique administrée par environnement (verrouillable en prod), `light/dark/system` reste une
préférence personnelle inchangée.** Ce qui suit corrige un point factuel erroné soulevé pendant
l'échange et fixe la direction technique cible.

### Correctif factuel : `APP_ENV` ne distingue toujours pas prod/preprod

Une proposition intermédiaire suggérait de s'appuyer sur `APP_ENV=production` vs `APP_ENV=preprod`
pour identifier l'environnement, en affirmant que c'est déjà le cas en production actuellement.
**C'est faux, vérifié contre le repo, pas contre un souvenir :**
- [README.md:24](../README.md#L24) documente `APP_ENV=production` pour la prod.
- [.env.preprod.example](../.env.preprod.example) documente **le même** `APP_ENV=production` pour
  la preprod, explicitement pour garantir qu'aucun `app()->environment('production')` ne diverge.
- `environment: production_elm` dans
  [.github/workflows/deploy-hostinger-admin.yml](../.github/workflows/deploy-hostinger-admin.yml)
  est un **environnement GitHub Actions** (scope de secrets CI/CD) — un espace de noms totalement
  différent d'`APP_ENV`, à ne pas confondre.
- `formation.eau-la-maman.com` (la preprod) n'est même pas encore déployée à ce jour — il n'existe
  donc aucune config Hostinger réelle à observer qui contredirait `.env.preprod.example`.

**`APP_ENV` reste donc hors-jeu comme signal d'identification de l'environnement.** Le point resté
valable de l'analyse initiale (section 4 ci-dessus) est la bonne base : chaque environnement a de
toute façon sa **propre base de données**, donc un réglage qui vit en base est déjà, mécaniquement,
scopé par environnement — sans qu'aucune colonne `environment` ni comparaison `APP_ENV` ne soit
nécessaire.

### Séparation retenue : politique (fichier) vs valeur active (base)

Deux choses distinctes, à ne pas mélanger dans un seul mécanisme :

1. **La politique** (le preset/liste de couleurs autorisées pour *cet* environnement, et si c'est
   verrouillé) — c'est de la config d'infra, pas de la donnée métier. Elle doit vivre dans le
   `.env` de chaque déploiement, comme `SENTRY_ENVIRONMENT` ou `MAIL_MAILER` le font déjà pour
   d'autres différences preprod/prod. **Important : pas en `VITE_*`** (ces variables-là sont figées
   au build JS, cf. section 2) — une variable serveur classique, lue via `config()`/`env()` côté
   Laravel à chaque requête, exposée au frontend via `HandleInertiaRequests::share()`. Ça reste
   changeable sans rebuild JS (juste `.env` + redéploiement backend), et ne nécessite toujours
   aucune notion d'`environment` en base : c'est le fichier `.env` propre à chaque déploiement qui
   joue ce rôle, exactement comme aujourd'hui pour les autres variables qui diffèrent par
   environnement.
2. **La valeur active choisie par l'admin** (parmi la liste autorisée, si non verrouillé) — ça,
   c'est une donnée administrée, qui doit vivre en base pour être modifiable depuis l'UI sans
   déploiement. Le pattern `Parametre` (section 4) est la bonne base à réutiliser : scopé
   organisation, déjà typé/admin/cache, déjà protégé par la permission `parametres.update`. Comme
   la base est déjà propre à l'environnement, aucune colonne `environment` n'y est nécessaire non
   plus.

### Ce que ça change concrètement (sans détail de code)

- `HandleInertiaRequests::share()` gagnerait un prop de politique de thème (verrouillé ou non,
  liste des couleurs autorisées, valeurs actives) — aujourd'hui il ne partage rien sur le thème.
- Toute tentative de changer preset/primary/surface devrait être **validée côté serveur** contre la
  liste autorisée de l'environnement — aujourd'hui `updatePrimeVueTheme`/`updatePrimeVuePrimary` ne
  passent par aucune requête serveur (section 3), c'est le vrai trou à combler.
- `useAppearance.ts` n'utiliserait plus `localStorage` comme source de vérité pour ces 3 axes
  (seulement pour `appearance` = light/dark/system, qui reste personnel) — le state initial
  viendrait du prop partagé par le serveur.
- `VITE_PRIMEVUE_*` redescend au rang de **filet de secours ultime** (si le nouveau mécanisme
  n'a rien à proposer), plus la "source principale" qu'il est aujourd'hui — cohérent avec le fait
  que ces valeurs sont déjà identiques entre prod et preprod actuellement (section 2).
- "Bleu et ses voisins" reste une constante à curer à la main (`blue`, `sky`, `indigo`, `cyan` a
  minima) — aucune donnée existante ne permet de la déduire automatiquement (section 5).
- Repère visuel "hors prod" (bandeau FORMATION/PRÉPRODUCTION évoqué dans l'échange) : pas besoin de
  nouvelle variable — `APP_NAME` porte déjà ce signal aujourd'hui (`"Eau-la-maman [PREPROD]"` dans
  `.env.preprod.example`, `"Eau-la-maman [E2E]"` dans `.env.e2e`) et `HandleInertiaRequests` le
  partage déjà à chaque page via le prop `name`. Un bandeau peut se dériver de ce prop existant
  sans rien ajouter côté config.
- Rappel opérationnel (déjà vécu sur ce projet, cf. mémoire `project_env_test_isolation`) : toute
  valeur lue via `config()`/`env()` est invalidée par un `config:cache` figé — un
  `config:clear` après tout changement de `.env` reste impératif, sans quoi la politique appliquée
  peut silencieusement rester l'ancienne.

---

## État implémenté (2026-08-14)

La direction ci-dessus est implémentée. Ce qui suit remplace l'ancienne section "Points
d'attention" (obsolète : elle décrivait l'architecture 100% client d'avant cette implémentation).

### Backend

| Fichier | Rôle |
|---|---|
| [config/theming.php](../config/theming.php) | Politique par déploiement (`THEME_ALLOWED_*` / `THEME_DEFAULT_*` en `.env`, jamais administrable) |
| [app/Support/Theming/ThemeCatalog.php](../app/Support/Theming/ThemeCatalog.php) | Source unique des valeurs valides + `BLUE_FAMILY` (miroir des unions TS) |
| [app/Services/ThemePolicyService.php](../app/Services/ThemePolicyService.php) | Autorité serveur : `allowed*()`, `is*Locked()`, `resolveActiveTheme()` (cascade DB → défaut déploiement → 1ère valeur autorisée) |
| [app/Models/Parametre.php](../app/Models/Parametre.php) | `GROUPE_THEME` + `getTheme*()`/`setTheme()` — persistance de la valeur active, scopée `organization_id` |
| [app/Http/Requests/Settings/UpdateThemeRequest.php](../app/Http/Requests/Settings/UpdateThemeRequest.php) | `Rule::in()` contre `ThemePolicyService` — seule porte d'écriture validée |
| [app/Http/Controllers/Settings/ThemeController.php](../app/Http/Controllers/Settings/ThemeController.php) | `edit`/`update`, permissions `parametres.read`/`update` |
| [app/Http/Middleware/HandleInertiaRequests.php](../app/Http/Middleware/HandleInertiaRequests.php) | Partage `theme` (actif + autorisé + verrouillé) à **chaque page**, y compris invité (fallback `ModuleService::publicOrganization()`) |

**Garde-fou important** : `ParametreController` (formulaire générique) refuse explicitement
`GROUPE_THEME` (édition → 404, listing → exclu) — sans ça, son typage générique
(`string|max:1000`) accepterait n'importe quelle couleur et court-circuiterait
`ThemePolicyService`. Un seul chemin d'écriture validée doit exister.

### Frontend

| Fichier | Rôle |
|---|---|
| [resources/js/composables/useEnvironmentTheme.ts](../resources/js/composables/useEnvironmentTheme.ts) | Lit `usePage().props.theme` (jamais localStorage), `update()` fait le round-trip serveur, `watchEnvironmentTheme()` réapplique les couleurs à chaque changement du prop |
| [resources/js/composables/useAppearance.ts](../resources/js/composables/useAppearance.ts) | Réduit à `light/dark/system` uniquement — appelle `applyEnvironmentTheme()` au toggle pour resynchroniser le pont shadcn avec le thème actif |
| [resources/js/lib/primevue-theme.ts](../resources/js/lib/primevue-theme.ts) | Mécanique pure (palettes, `apply*()`) — plus aucune fonction de résolution localStorage/`VITE_*` |
| [resources/js/pages/settings/Theme.vue](../resources/js/pages/settings/Theme.vue) | Écran admin : swatches limités à `allowed.*`, verrouillage visuel si `locked.*`, brouillon + bouton "Enregistrer" explicite |
| [resources/js/components/EnvironmentBadge.vue](../resources/js/components/EnvironmentBadge.vue) | Bandeau hors-prod dérivé du suffixe `[...]` déjà présent dans `APP_NAME` — aucune nouvelle variable |
| [resources/js/components/AppearanceTabs.vue](../resources/js/components/AppearanceTabs.vue) | Réduit à light/dark/system (le picker PrimeVue a déménagé vers `settings/Theme.vue`) |

### `.env*` — politique par environnement

- `.env` / `.env.example` : `THEME_DEFAULT_*` seuls (pas de `THEME_ALLOWED_*` → tout le catalogue
  autorisé, comportement local inchangé).
- `.env.production.example` : `THEME_ALLOWED_PRESETS/PRIMARIES/SURFACES` à une seule valeur chacun
  (`starter`/`blue`/`slate`) → verrouillé de facto (`count(allowed) <= 1`, pas de flag séparé).
- `.env.preprod.example` : whitelist excluant `blue`/`sky`/`cyan`/`indigo` — 3ᵉ dérogation
  documentée en tête de fichier (les 2 précédentes : URL/DB/nom, `MAIL_MAILER=log`).
- `.env.e2e` : défaut `orange` (repère visuel uniquement, aucun test n'affirme sur une couleur).

### Comment étendre

- **Ajouter une couleur/preset au catalogue** (nouvelle valeur PrimeVue) : ajouter dans
  `ThemeCatalog` (PHP) **et** les unions + palettes de `primevue-theme.ts` (TS) — les deux listes
  sont volontairement séparées (langages différents), tout le reste (validation, UI) est générique
  une fois ces deux entrées posées.
- **Changer la politique d'un environnement** : éditer son `.env` (`THEME_ALLOWED_*`/`THEME_DEFAULT_*`),
  puis `php artisan config:clear` (la politique est lue par `config()`, un cache figé la rendrait
  invisible — cf. mémoire `project_env_test_isolation`, déjà vécu sur ce projet).
- **`light/dark/system`** reste géré par `useAppearance.ts`/localStorage, complètement indépendant.
- Tests : [tests/Feature/ThemeSettingsTest.php](../tests/Feature/ThemeSettingsTest.php) (permissions,
  validation serveur, partage inter-utilisateurs, retombée sur valeur devenue interdite, invité) et
  [tests/Unit/ThemeCatalogTest.php](../tests/Unit/ThemeCatalogTest.php) (parsing de la politique).
