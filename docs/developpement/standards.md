# Standards de développement

> **Statut** : en place · **Dernière vérification** : 15/11/2026, d'après
> `scripts/check-ui-standards.mjs`, `.github/workflows/lint.yml` et les tests PHPUnit cités
>
> **Document de référence unique.** Il consolide les règles qui figuraient dans `CLAUDE.md`,
> `AGENTS.md` et `.claude/CLAUDE.md` (§ 10). Ces fichiers renvoient désormais ici : une règle se
> modifie **dans ce document**, dans la même pull request que le contrôle automatique concerné.

Ce document s'adresse aux développeurs et aux agents IA. La partie A porte sur l'interface, la
partie B sur les règles communes à tout changement.

## Les contrôles automatiques

`npm run lint:standards` (`node scripts/check-ui-standards.mjs`) analyse les fichiers `.vue` de
`resources/js/pages`, `resources/js/components` et `resources/js/layouts` (sauf
`components/ui/`, `StatusDot.vue` et `DataFilters.vue`). Il tourne dans le job `quality` de
`lint.yml`, sur **toutes** les pull requests (vers `dev`, `pre-prod`, `production`, `main`). Une
violation fait échouer la pull request.

| Contrôle (`[type]` affiché) | Règle | Désactivé par `ui-standard-ignore-file` |
|---|---|---|
| `badge` | [A1](#a1-statuts--un-point-coloré-jamais-un-fond-coloré) — statut en pastille à fond coloré | oui |
| `filter` | [A2](#a2-pages-de-liste-et-filtres) — au moins deux `ref` `filterXxx`/`filtreXxx` sans `DataFilters.vue` (pages seulement) | oui |
| `duplicate-search` | [A2](#a2-pages-de-liste-et-filtres) — champ de recherche fait main dans la partie bureau, en plus de `DataFilters` (pages seulement) | oui |
| `list-page-actions` | [A2](#a2-pages-de-liste-et-filtres) — page migrée sans `ListPageActions` ou sans `trigger-only` | oui |
| `copy-icon` | [A4](#a4-icône-de-copie) — icône de copie | oui |
| `toast` | [A5](#a5-toasts) — `<Toast>` sans `position="top-right"` | **non** |
| `periode` | [A7](#a7-filtres-de-période) — `type: 'date-range'` ou liste de périodes codée en dur | **non** (dérogation propre, voir A7) |

Contrôlés par PHPUnit (job `PHPUnit` de `tests.yml`) :

| Test | Règle |
|---|---|
| `tests/Feature/Documents/ModeleDocumentPdfTest.php` | [A6](#a6-factures-et-bons-pdf) — modèle PDF partagé |
| `tests/Feature/Periodes/RegistreFiltresPeriodeTest.php` | [A7](#a7-filtres-de-période) — registre des écrans filtrés par période |
| `tests/Unit/Architecture/PeriodesArchitectureTest.php` | [A7](#a7-filtres-de-période) — aucun calcul de bornes hors du moteur |

Les autres règles (A3, A8 à A12, partie B) se vérifient **en revue de code**.

### Échappatoires

| Commentaire | Effet | Conditions |
|---|---|---|
| `ui-standard-ignore-file` (n'importe où dans le `.vue`) | Désactive `badge`, `filter`, `duplicate-search`, `list-page-actions` et `copy-icon` pour ce fichier | Cas réellement hors périmètre (ex. un badge de catégorie qui ressemble à un statut). Ajouter à côté une courte explication. À utiliser avec parcimonie. |
| `periode-standard-ignore: <raison>` | Désactive le contrôle `periode` pour ce fichier | Raison obligatoire, exception justifiée dans `PeriodesArchitectureTest` côté serveur et ligne dans [periodes.md](../periodes.md) (« Dérogations »). |

Aucun commentaire ne désactive le contrôle `toast`.

---

## A. Standards d'interface

### A1. Statuts : un point coloré, jamais un fond coloré

Tout **statut d'entité** (commande, facture, dépense, transfert, période, paiement…) s'affiche avec
`resources/js/components/StatusDot.vue` :

```vue
<StatusDot :status="commande.statut" :label="commande.statut_label" />
```

- `status` reçoit la valeur brute du serveur (ex. `"livraison_en_cours"`) ; la couleur du point
  vient de la table `STATUS_COLOR_MAP` en tête de `StatusDot.vue`.
- **Interdit** : un `<span>`, `<Badge>` ou `<button>` qui combine `rounded-full` et un fond
  `bg-*-50` / `bg-*-100` pour afficher un statut. Seul le point est coloré, jamais le fond du
  texte.
- Une **nouvelle valeur de statut** s'ajoute uniquement dans `STATUS_COLOR_MAP`, jamais dans une
  table de couleurs locale à la page.
- Ne s'applique qu'aux statuts. Les badges de **catégorie, type ou rôle** (type de produit, rôle
  utilisateur, catégorie de dépense) peuvent garder un fond coloré.

### A2. Pages de liste et filtres

**Référence pour toute nouvelle page de liste et toute page de liste modifiée** : l'en-tête
`resources/js/components/ListPageActions.vue` et `DataFilters` en mode **`trigger-only`**.

```
[Titre + sous-titre]                     [Exporter] [Importer] [Filtres] [Nouveau]
```

```vue
<ListPageActions>
    <template #export>...</template>
    <template #import>...</template>
    <template #filters>
        <DataFilters trigger-only url="/produits" :values="filters" :fields="filterFields" />
    </template>
    <template #primary>...</template>
</ListPageActions>
```

- **Ordre imposé par le composant** : Exporter → Importer → Filtres → Nouveau. La page ne place
  pas ces boutons elle-même : elle remplit les slots `#export`, `#import`, `#filters`, `#primary`.
- Un slot sans action est omis (pas de bouton fantôme) : pas de `#filters` sans filtre pertinent,
  pas de `#primary` sans création. Le bouton `#primary` est toujours « Nouveau &lt;entité&gt; », à
  droite.
- `trigger-only` : un seul bouton **Filtres** dans l'en-tête, qui ouvre le tiroir ; tous les champs
  sont dans le tiroir, aucune barre de champs au-dessus du tableau.
- Ordre des champs dans le tiroir : **Agence** (si données multi-sites) → **Statut** →
  **Recherche** → **Période** → autres filtres métier (véhicule, client, propriétaire, type,
  catégorie, montant…).
- Le filtre **Agence** est obligatoire dès que la page affiche des données de plusieurs sites, et
  envoie `site_ids[]` au serveur.
- **Aucune recherche globale** (`search`) : le composant ne la propose plus. Un filtre texte est un
  champ `type: 'text'` de `filterFields`.
- `DataFilters`, l'URL et le serveur sont l'unique source des filtres : pas de second filtrage
  local sur une liste pilotée par le serveur.
- **Interdit** (contrôle `filter`) : plusieurs `ref` `filterXxx` / `filtreXxx` dans une page sans
  importer `DataFilters.vue`. **Interdit** (contrôle `duplicate-search`) : un
  `<input v-model="search">` dans la partie bureau d'une page qui utilise `DataFilters`. Un `ref`
  `search`/`mobileSearch` limité au bloc mobile (`sm:hidden`) reste autorisé.
- **Exception, barre rapide (Ventes seulement, 25/10/2026)** : `recherche` et quelques filtres texte
  fréquents (`inline: true`) en tête du tableau via `components/filters/QuickFilters.vue`, avec
  `<DataFilters trigger-only quick-bar>` dans l'en-tête. Aucun champ affiché deux fois, filtrage
  serveur. Ne pas l'étendre à une autre liste sans demande explicite
  ([filters.md](../filters.md#barre-rapide-du-tableau-quickfiltersvue--quick-bar)).

**Pages pas encore migrées.** Au 15/11/2026, sur 58 fichiers qui utilisent `<DataFilters>`, 23 sont
en `trigger-only` et **35** gardent l'ancienne barre de filtres. Pour ces 35 pages, l'ancienne règle reste valable tant qu'elles ne
sont pas modifiées : barre **[Agence] → [Statut] → [autres champs `inline: true`] → [bouton
Filtres]**, champs avancés dans le tiroir. **Aucune migration en masse.** Une page modifiée passe au
standard `trigger-only` et son chemin est ajouté à `LIST_PAGE_ACTIONS_REQUIRED` dans
`scripts/check-ui-standards.mjs` (liste qui ne fait que s'allonger : une page n'en est jamais
retirée). Seules les pages de cette liste sont contrôlées par `list-page-actions`.

Référence complète du composant : [filters.md](../filters.md).

### A3. Visibilité des actions selon les permissions

Un bouton ou lien Créer, Modifier, Supprimer, ou une action métier (valider, encaisser,
annuler…), ne s'affiche jamais à un utilisateur qui n'a pas la permission serveur correspondante,
même si le serveur refuse déjà la requête.

```vue
<Button v-if="can('categories.create')" @click="openCreate">Nouvelle catégorie</Button>
```

- Utiliser `can('ressource.action')` de `resources/js/composables/usePermissions.ts` : jamais une
  vérification ad hoc, jamais un simple `:disabled` sur un bouton qui reste visible.
- La clé doit être **exactement** celle vérifiée côté serveur (`$this->authorize(...)`,
  `$user->can(...)`, policy) : lire le contrôleur ou la policy avant d'écrire le `v-if`, ne jamais
  deviner.
- Si toute la page est déjà réservée aux détenteurs de cette permission (`authorize` sur
  `index`/`viewAny`), le `can()` est redondant mais sans risque.
- S'applique aussi aux liens de navigation (menu latéral…) vers une action protégée.

Le masquage n'est qu'un confort : la protection réelle reste côté serveur (B1).

### A4. Icône de copie

Toute copie dans le presse-papiers (numéro de facture, lien, clé…) utilise l'icône PrimeIcons
`pi pi-clipboard`, et cette icône ne sert **qu'à** copier.

```vue
<button type="button" title="Cliquez pour copier le numéro de facture" @click="copier">
    <i :class="copie ? 'pi pi-check' : 'pi pi-clipboard'" />
</button>
```

- **Infobulle obligatoire** : le bouton porte un `title` statique qui commence par « Cliquez pour
  copier » suivi de ce qui est copié.
- **Interdit** : les icônes Lucide `Copy`, `CopyCheck`, `ClipboardCopy`, et `pi-copy`.
- **Interdit** : `pi pi-clipboard` dans un fichier qui ne copie rien (ni `navigator.clipboard` ni
  `useClipboard`) : choisir une autre icône.
- La coche de confirmation après copie est libre (`pi pi-check` recommandé).

### A5. Toasts

Tout composant PrimeVue `<Toast>` déclare explicitement `position="top-right"` :

```vue
<Toast position="top-right" />
```

- **Interdit** : `bottom-*`, `center` ou une position dynamique.
- S'applique au Toast global comme aux groupes déclarés dans une page, dans les pages, composants
  et layouts. Les appels `toast.add(...)` ne changent pas.
- Aucune échappatoire.

### A6. Factures et bons PDF

Toute facture ou tout bon en PDF (facture d'achat, bon de commande, facture de vente, et tout
nouveau document de ce type) utilise le modèle officiel ([documents-pdf.md](../documents-pdf.md),
ADR 0033) :

```blade
<x-pdf.document :document="$document" />
```

- Les données sont préparées par un constructeur `app/Support/Documents/XxxDocument.php` qui
  retourne un `DocumentPdf` ; le rendu passe par `DocumentPdfRenderer`.
- **Interdit** : une nouvelle vue PDF de facture ou de bon avec son propre HTML/CSS (`<style>`,
  en-tête ou totaux recopiés). Un nouveau besoin de présentation s'ajoute au modèle (paramètre de
  `DocumentPdf` ou slot du composant), pour tous les documents.
- Couleurs : celles du design d'origine de la facture d'achat ; seul le nom de l'organisation prend
  les deux tons de `CharteDocument`. Pied de page légal : celui de l'organisation (Paramètres →
  Organisation), jamais écrit dans le modèle.
- Une mention propre à un document reste propre à ce document (ex. « Ce document n'est pas
  l'original du fournisseur » : facture d'achat uniquement).
- Tout nouveau type est ajouté à `tests/Feature/Documents/ModeleDocumentPdfTest.php`.

### A7. Filtres de période

Règle stricte (ADR 0057, [periodes.md](../periodes.md)) : aucun filtre temporel en dehors du
moteur centralisé des périodes.

```php
$periode = Periode::depuisRequeteValidee($request);   // App\Support\Periodes\Periode
$periode->appliquer($query, 'created_at');
```

```ts
{ key: 'periode', label: 'Période', type: 'period', defaultValue: 'tout' }
```

- Deux modes seulement : `periode=<clé>` (`aujourd_hui`, `hier`, `cette_semaine`,
  `semaine_precedente`, `ce_mois`, `mois_precedent`, `cette_annee`, `annee_precedente`, `tout`) ou
  `date_debut` + `date_fin` (incluses, ordonnées ; `date_debut` obligatoire ; sans `date_fin` =
  jusqu'à aujourd'hui, résolu par le serveur). Jamais les deux à la fois.
- **Interdit** : calculer des bornes dans un contrôleur, un service ou le navigateur
  (`startOfMonth`, `subMonth`, `whereDate`, `new Date()`…) ; lire `date_debut`/`date_fin` à la
  main ; un champ `type: 'date-range'` ; une liste de périodes codée en dur côté front (les choix
  viennent du serveur : `page.props.periodes`, `Periode::options()`).
- Une période invalide est refusée avec un message, jamais remplacée en silence.
- Les périodes métier (quinzaines P1/P2, mois comptable, paie) restent à leurs services dédiés.
- Tout nouvel écran filtré par période est inscrit dans
  `tests/Feature/Periodes/RegistreFiltresPeriodeTest.php`.

### A8. Couleurs sémantiques

| Sens | Couleur | Quand |
|---|---|---|
| Succès | vert | situation normale ou réussite |
| Avertissement | orange / ambre | attention, mais l'opération reste autorisée |
| Danger | rouge | erreur ou opération réellement bloquée |
| Information | bleu | information sans anomalie |

Une situation autorisée ne s'affiche pas en rouge parce qu'une anomalie existe. Exemple : impayé +
dérogation valide + commande autorisée → **avertissement**, pas danger.

### A9. Cartes, blocs et tableaux

- **Ne jamais ajouter une carte ou un autre bloc à un écran sans demande explicite**, même si le
  besoin semble évident : le proposer dans le compte rendu et attendre la réponse.
- Cartes de synthèse : `resources/js/components/KpiCard.vue` ; bouton d'information :
  `resources/js/components/InfoTooltip.vue`. Tailles, grilles et variante compacte :
  [ui-cartes-et-informations.md](../ui-cartes-et-informations.md).
- Tableaux : les statuts dans les cellules suivent A1, les filtres A2. Aucune autre règle propre
  aux tableaux n'est fixée à ce jour.
- Réutiliser les composants partagés et les styles existants avant d'en créer de nouveaux.

### A10. Paramètres et choix Oui / Non

- Tout paramètre d'organisation est isolé par `organization_id`, protégé par la permission
  existante la plus adaptée, et a une valeur par défaut sûre.
- Un booléen : **une seule case à cocher** clairement libellée.
- Si les deux choix **Oui** et **Non** doivent être visibles : des **boutons radio** (deux cases à
  cocher seraient ambiguës).
- La permission est vérifiée aussi côté serveur : un paramètre n'est jamais modifiable seulement
  dans le front.

### A11. Historique, motifs et modales

- Le motif d'un mouvement ou d'une activité vient de sa **source métier réelle** (vente, transfert,
  ajustement, production…), jamais déduit du signe ou du montant.
- `DataFilters.vue` est obligatoire pour les filtres des pages **Index**. Un filtre simple dans une
  modale d'historique peut suivre le composant déjà utilisé dans cette modale.
- Le filtre ne propose que des motifs réellement pris en charge par les données et le serveur.

### A12. Conventions en usage, non contrôlées

Constatées dans le code et la documentation métier ; elles ne figuraient dans aucun fichier
d'instructions.

- **Fiche à onglets** : l'onglet actif est dans l'URL (`?tab=…`) via
  `resources/js/composables/useUrlTab.ts` (ex. [fiche-agent.md](../fiche-agent.md)).
- **Bouton de confirmation d'une action serveur** : indicateur de chargement et boutons verrouillés
  pendant la requête (ex. [precommandes.md](../precommandes.md)).

---

## B. Règles transverses

### B1. Le serveur fait foi

Toute règle qui bloque ou autorise une action métier (stock, solde, statut, permission, plafond,
tarification, solvabilité, dette, commissions, cashback, validation financière…) est contrôlée
**côté serveur**. Un bouton désactivé, un filtre front ou un message Vue améliore l'expérience mais
ne remplace jamais la règle serveur.

- Centraliser la règle dans le service, la policy ou le mécanisme métier existant.
- Contrôler à nouveau les opérations différées ou concurrentes au moment de leur exécution réelle
  (ex. création de commande puis validation du chargement).
- Une action bloquée dans l'interface a toujours son équivalent serveur.
- Ne jamais faire confiance à un montant ou à une règle sensible venant du front.

### B2. Organisations et autorisations

- Toute donnée métier respecte `organization_id` ; aucun accès d'une organisation à une autre.
- Conserver les policies, middlewares et autorisations existants ; ne jamais les contourner pour
  simplifier.
- Un nouveau point d'entrée sensible a ses tests d'isolation entre organisations.
- Front Nuxt et compte unique (ADR 0055) : les routes de l'espace client n'ont pas de garde globale
  par rôle ; chaque endpoint limite ses données aux activités du compte.

### B3. Secrets et données personnelles

Le dépôt GitHub est **public** : tout ce qui est commité est lisible par tous, historique compris.

- Aucun mot de passe, jeton, clé ou DSN réel dans le code, la documentation ou les fichiers
  `.env.*` versionnés (`.env.testing`, `.env.e2e` ne contiennent que des valeurs de test).
- Aucune donnée personnelle réelle (numéro de téléphone, adresse e-mail) dans un nouveau seeder ou
  un nouveau test : utiliser des numéros fictifs.
- Un secret commité par erreur est considéré comme compromis : il se change à sa source ; le
  retirer du code ne suffit pas.

### B4. Migrations

- Jamais de migration destructive sans accord explicite ; préserver les données existantes.
- **Ne jamais renommer, découper ni supprimer une migration déjà déployée** : ajouter une nouvelle
  migration ([hostinger.md](../deploiement/hostinger.md#règles-à-respecter)).
- Les tests tournent sous SQLite, la production sous MySQL : une migration nouvelle se vérifie aussi
  sous MySQL (ex. MySQL limite les noms d'index à 64 caractères, SQLite non).

### B5. Documentation et décisions

- Une modification de règle métier met à jour la documentation de `docs/` dans la **même pull
  request** ; le code, les tests et la documentation décrivent le même comportement.
- Une décision structurante a son ADR ([index](../adr/README.md)).
- Un nouveau document est ajouté au [sommaire](../README.md).

### B6. Vérifications avant livraison

Lancer au minimum les contrôles du périmètre touché, puis indiquer dans le compte rendu les
commandes exécutées et leurs résultats.

| Changement | Commandes |
|---|---|
| Interface (`.vue`, `.ts`) | `npm run lint:standards`, `npm run typecheck`, `npx eslint . --max-warnings 0`, `npm run format:check`, tests Vitest de la page |
| PHP | `vendor/bin/pint --test` (ou `vendor/bin/pint` pour corriger), tests PHPUnit du domaine |
| Règle métier, bug | un test qui empêche la régression ; ne jamais supprimer ni assouplir un test pour faire passer un changement |
| Contrat d'API | `composer openapi:export` puis commiter `docs/openapi/*.json` (contrôlé en CI) |
| Parcours utilisateur | le scénario Playwright concerné |

Ce sont les contrôles de la CI : [ci-cd.md](ci-cd.md). Commandes de test détaillées :
[tests.md](tests.md).
