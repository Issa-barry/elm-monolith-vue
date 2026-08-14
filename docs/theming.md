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

## Points d'attention pour toute modification

- **Ajouter une couleur** primaire/surface = ajouter une entrée dans `PRIMARY_PALETTES` /
  `SURFACE_PALETTES` (11 nuances) **et** dans le type `PrimeVuePrimaryName`/`PrimeVueSurfaceName` dans
  [primevue-theme.ts](../resources/js/lib/primevue-theme.ts) — rien d'autre à toucher, tout le reste
  (UI de sélection, résolution, application) est générique.
- **`starter` est spécial** : ne pas oublier le verrouillage primaire=blue/surface=slate dans
  `updatePrimeVueTheme()` si on touche à cette logique.
- Le cookie `appearance` est ce qui permet un premier rendu correct côté serveur (pas de flash) ; si on
  ajoute un state persistant similaire, il faut le même pattern (cookie + localStorage), pas juste
  localStorage seul.
- Il n'existe **pas** de fichier de config Tailwind listant les couleurs du thème — tout part des
  palettes hardcodées dans `primevue-theme.ts`. Toute divergence entre l'UI PrimeVue et l'UI
  shadcn/Tailwind vient forcément d'un défaut de synchro entre `applyPrimeVue*Color()` et
  `applyAppThemeColors()`.
