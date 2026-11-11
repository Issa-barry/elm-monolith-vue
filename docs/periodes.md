# Filtres de période — convention obligatoire

Décision : [ADR 0057](adr/0057-standard-unique-des-periodes.md). Audit initial :
[audit-filtres-periodes.md](audit-filtres-periodes.md).

> Une seule définition des périodes, un seul moteur de calcul côté Laravel, une validation commune et
> une utilisation uniforme par tous les modules. Les interfaces transmettent une période ; elles ne
> calculent jamais ses bornes.

Cette règle s'applique à tous les développeurs et à tous les agents IA, pour tout nouvel écran et toute
évolution d'un filtre existant. Une dérogation doit être justifiée (voir « Dérogations »).

## 1. Deux modes, un seul objet

| Mode | Paramètres envoyés | Exemple |
| --- | --- | --- |
| Période rapide | `periode=<clé>` | `GET /backoffice/ventes?periode=ce_mois` |
| Intervalle personnalisé (« Du » / « Au ») | `date_debut=AAAA-MM-JJ&date_fin=AAAA-MM-JJ` | `GET /backoffice/ventes?date_debut=2026-11-01&date_fin=2026-11-04` |

Les deux modes produisent le même objet `App\Support\Periodes\Periode` (clé, début, fin).

### Périodes rapides

| Libellé | Clé | Bornes |
| --- | --- | --- |
| Aujourd'hui | `aujourd_hui` | jour en cours |
| Hier | `hier` | veille |
| Cette semaine | `cette_semaine` | lundi → dimanche |
| Semaine dernière | `semaine_precedente` | lundi → dimanche de la semaine précédente |
| Ce mois | `ce_mois` | 1er → dernier jour du mois |
| Mois dernier | `mois_precedent` | mois précédent (jamais de débordement un 29, 30 ou 31) |
| Cette année | `cette_annee` | 1er janvier → 31 décembre |
| Année dernière | `annee_precedente` | année précédente |
| Toutes les périodes | `tout` | aucune borne |
| Période personnalisée | `personnalisee` | `date_debut` → `date_fin` (aujourd'hui si vide) |

`tout` (et non `toutes`) est la clé déjà publiée dans l'API et le front Nuxt : elle ne change pas.

Extension réservée au tableau de bord : `t1`–`t4` (trimestres) et `s1`, `s2` (semestres) de l'année en
cours (`Periode::TRIMESTRES_SEMESTRES`).

Anciennes clés encore acceptées (liens et vues enregistrées) : `today`, `week`, `month`, `all`,
`semaine_derniere`, `mois_dernier`, et `date_from`/`date_to` pour les dates. Ne plus les produire.

## 2. Règles

1. **Un seul calculateur** : `Periode::rapide()` / `Periode::depuisRequeteValidee()`. Aucun contrôleur,
   service ou composant front ne calcule ses propres bornes.
2. **Fuseau** : celui de l'application (`config/app.php`, `UTC` = heure de Conakry, sans heure d'été).
3. **Dates incluses** : du 1er au 4 novembre = toutes les opérations du 4 novembre jusqu'à 23:59:59.
4. **Date de début obligatoire, fin facultative** (décision du 10/11/2026) : sans `date_fin`, la période va
   jusqu'à aujourd'hui (résolu par le serveur, jamais par le navigateur) ; une date de début dans le futur
   sans date de fin est refusée (« Sans date de fin, la date de début ne peut pas dépasser aujourd'hui. »).
   Une date de fin seule est refusée. `date_fin` ≥ `date_debut`. La fin ouverte est renvoyée vide au
   filtre (`valeursFiltre()`) : une vue enregistrée suit le jour courant.
5. **Exclusivité** : `periode` et des dates ensemble sont refusés (« Choisissez une période ou des
   dates, pas les deux »). Exception tolérée : une clé sans effet (`tout`, `all`, `personnalisee`)
   envoyée avec des dates est retirée avant validation.
6. **Erreur explicite, jamais de repli silencieux** : back-office → retour à la page avec le message
   sous le champ (`errors.date_fin`…) ; API → 422.
7. **Bornes SQL semi-ouvertes** : `colonne >= début` et `colonne < lendemain 00:00`
   (`Periode::appliquer()`), sans `whereDate`/`whereMonth`/`whereYear` sur la colonne, pour garder
   l'index et ignorer les microsecondes. Colonne `DATE` (ex. `date_depense`) : `colonneDate: true`.
8. **Même résolution partout** : liste, statistiques, exports Excel/CSV et impression d'un écran
   appellent la même méthode avec la même requête.
9. **Défaut propre à l'écran** : sans choix, chaque écran garde son défaut (Factures `ce_mois`,
   tableau de bord `ce_mois`, rapport d'activité `aujourd_hui`, autres listes `tout`).
10. **Périodes métier à part** : quinzaines P1/P2, mois comptable et périodes de paie ne passent pas
    par `Periode` (voir § 5).

## 3. Utilisation

### Backend

```php
use App\Support\Periodes\Periode;

$periode = Periode::depuisRequeteValidee($request);              // défaut « Toutes les périodes »
$periode = Periode::depuisRequeteValidee($request, 'ce_mois');   // autre défaut
$periode->appliquer($query, 'created_at');                       // colonne datetime
$periode->appliquer($query, 'date_depense', colonneDate: true);  // colonne date

return Inertia::render('Module/Index', [
    'filters' => [...$filters, ...$periode->valeursFiltre()],    // periode, date_debut, date_fin
]);
```

- Choix restreints : `Periode::depuisRequeteValidee($request, 'aujourd_hui', $cles)`. Sans
  `personnalisee` dans `$cles`, des dates sont refusées (« Cet écran ne propose pas de dates libres »).
- Données déjà chargées en PHP : `$periode->contient($date)`.
- Libellé d'export : `$periode->libelle()` (« Ce mois (du 01/11/2026 au 30/11/2026) »).
- FormRequest : `Periode::regles()` et `Periode::messages()`.
- Vues enregistrées : `Periode::clesAcceptees()` (déjà branché dans `SavedFilterScopes`).

### Front (back-office)

```ts
{ key: 'periode', label: 'Période', type: 'period', defaultValue: 'tout' }
```

- Les choix viennent du serveur (`page.props.periodes`, partagé par `HandleInertiaRequests`) : ne pas
  passer d'`options` en dur.
- `DataFilters.vue` envoie `periode` pour un raccourci, `date_debut` + `date_fin` pour « Période
  personnalisée », et bloque « Appliquer » tant que les deux dates ne sont pas valides. L'erreur du
  serveur s'affiche sous le champ.
- Panneau « Filtres » : les champs « Du » / « Au » sont toujours visibles sous la liste (dès que
  l'écran propose `personnalisee`). Saisir une date passe en « Période personnalisée » ; choisir un
  raccourci efface les dates ; vider les deux dates revient au défaut de l'écran. Dans la barre, les
  dates n'apparaissent qu'après le choix « Période personnalisée ».
- Toute liste PrimeVue du panneau (modal) porte `append-to="self"` : ouverte dans `body`, ses choix
  ne sont pas cliquables.
- Un écran qui filtre des données déjà chargées (graphique du tableau de bord) reçoit les bornes de
  chaque choix (`Periode::optionsAvecBornes()`) : le navigateur ne lit jamais son horloge pour une
  période.

### Front Nuxt et mobile

Ils envoient la clé ou les deux dates, jamais de bornes calculées (`config/clientActivity.ts`).

## 4. Registre et contrôles CI

| Contrôle | Où | Bloque |
| --- | --- | --- |
| Registre des écrans | `tests/Feature/Periodes/RegistreFiltresPeriodeTest.php` | écran qui valide une période sans être inscrit ; écran inscrit qui n'accepte pas `periode=hier`, des dates valides, une date de début seule, ou qui ne refuse pas une date de fin seule / une fin avant le début avec le message commun |
| Architecture | `tests/Unit/Architecture/PeriodesArchitectureTest.php` | `startOfMonth`, `subMonth`, `whereDate`… dans un contrôleur ; lecture directe de `date_debut`/`date_fin` dans `app/Http` ; exception devenue inutile |
| Front | `scripts/check-ui-standards.mjs` (`npm run lint:standards`, job CI `quality`) | champ `type: 'date-range'` ; liste de périodes codée en dur (`value: 'ce_mois'`, `value: 'last_week'`…) |
| Moteur | `tests/Unit/Periodes/PeriodeTest.php` | bornes, changements de mois et d'année, année bissextile, semaine, dates identiques, alias, validation, SQL |

Ajouter un écran : utiliser `Periode::depuisRequeteValidee`, l'inscrire dans `REGISTRE` (ou
`REGISTRE_SANS_DATES`), déclarer le champ `period` côté front.

## 5. Dérogations

| Écran / endpoint | Raison | Suite |
| --- | --- | --- |
| Commissions (Livreurs, Propriétaires, Agences, Consultants), Ajustements, Périodes, Fiches | Période métier : quinzaines `AAAA-MM-P1/P2/M` (`CommissionPeriodeFilter`) | définitive |
| Salaires, Paie, Transmissions de paie | Période de paie (`annee`, `mois`) | définitive |
| Journal financier, Remises agences, Financement agence | Mois comptable (`annee`, `mois`) | définitive |
| Situation de trésorerie | Date de situation unique, pas une période | définitive |
| Réceptions fournisseurs | File des réceptions à faire, pas un historique | à revoir si demandé |
| Packings | Liste filtrée côté navigateur (statut seul) | à migrer avec un filtrage serveur |
| API espace client et parrain (`/v1/mobile/*`, `/v1/parrain/*`), pages client Inertia (`period` historique) | Contrat publié au front Nuxt ; travail ADR 0055 non commité sur les mêmes fichiers (décision du 04/11/2026) | lot API : `Periode::regles()` dans les FormRequest, `periode` sur `commandes/mine` et les endpoints parrain |

Côté front, une dérogation se déclare avec un commentaire `periode-standard-ignore: <raison>` (raison
obligatoire). Côté backend, elle s'inscrit dans les listes d'exceptions de `PeriodesArchitectureTest`,
avec sa raison.
