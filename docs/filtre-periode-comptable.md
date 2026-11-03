# Filtre période comptable de l'espace client

Filtre commun à l'**Accueil** et aux écrans **Commissions**, **Ventes** (écran Activité, onglet
« Livraisons ») et **Dépenses** de l'espace client Nuxt. Décision :
[ADR 0053](adr/0053-filtre-periode-comptable-espace-client.md).

## Paramètres d'API

Sur `GET /v1/mobile/dashboard`, `GET /v1/mobile/activite` et `GET /v1/mobile/depenses/mine` :

| Paramètre | Valeurs | Règle |
|---|---|---|
| `annee` | de 2000 à l'année en cours | requis avec `mois` ou `quinzaine` ; seul = l'année entière |
| `mois` | 1 à 12 | requis avec `quinzaine` ; omis = l'année entière |
| `quinzaine` | `P1`, `P2`, `TOUT` | omise = le mois entier |

| Quinzaine | Bornes incluses |
|---|---|
| `P1` | du 1er au 15 |
| `P2` | du 16 au dernier jour du mois (28, 29, 30 ou 31) |
| `TOUT` | du 1er au dernier jour du mois |

Le serveur calcule seul les bornes (`PeriodeComptableFiltre`, qui s'appuie sur
`PeriodeComptableService::dateRangeForCode`) et renvoie dans `filters` : `annee`, `mois`, `quinzaine`,
`date_debut`, `date_fin`. Sur le dashboard, `filters.period` vaut alors `custom`.

Sans ces paramètres, chaque endpoint garde exactement son comportement précédent : le filtre n'est
appliqué par défaut que par l'écran Nuxt.

**Réponse 422** : année hors bornes (y compris future), mois hors 1-12, quinzaine inconnue, paramètre
manquant (`mois` sans `annee`, `quinzaine` sans `mois`…), combinaison avec un autre filtre de date
(`date_debut`, `date_fin`, `period` sur le dashboard, `periode` sur l'activité et le dashboard).

Le paramètre s'appelle `quinzaine` et non `periode`, car `periode` désigne déjà les périodes rapides
(`periode=hier`).

### Périodes rapides de l'accueil

`GET /v1/mobile/dashboard` accepte aussi `periode`, résolu par le serveur (`SituationPeriode`, fuseau de
l'application, semaine du lundi au dimanche) et renvoyé avec ses bornes dans `filters` :

| `periode` | Bornes |
|---|---|
| `aujourd_hui`, `hier` | le jour |
| `cette_semaine`, `semaine_precedente` | du lundi au dimanche |
| `ce_mois`, `mois_precedent` | du 1er au dernier jour du mois |
| `cette_annee`, `annee_precedente` | du 1er janvier au 31 décembre |
| `tout` | aucune borne (`date_debut` et `date_fin` à `null`) |

La période en cours va jusqu'à sa fin : « Ce mois » donne le même résultat que le mois comptable en cours.

## Date de référence de chaque module

Le calendrier est commun, la date reste propre à chaque module. Aucune règle de date n'a changé pour
les ventes et les dépenses.

| Écran | Donnée | Date utilisée |
|---|---|---|
| Accueil et Commissions | part de vente | `commission_enveloppes.earned_at` (date de gain) |
| Accueil et Commissions | part logistique | `commission_logistique_parts.earned_at` (date de gain) |
| Accueil et Commissions | dépenses déduites, `depenses_total` | `depenses.date_depense` |
| Accueil | carte « Dépenses » mobile | `depenses.date_depense` (`total_valide` de `depenses/mine`) |
| Ventes | commande de vente | `validated_at`, sinon `created_at` |
| Ventes | transfert logistique | `date_depart_reelle`, sinon `created_at` |
| Dépenses | dépense | `depenses.date_depense` |

### Commissions : la date de gain, comme les fiches de paiement

`PeriodeCalculatorService` rattache les commissions aux périodes de paiement par `earned_at`. Le filtre
(`annee`/`mois`/`quinzaine` comme `periode`) reprend cette date pour que P1 et P2 regroupent les mêmes
commissions que les fiches.

Les anciens filtres du dashboard (`period`, `date_debut`/`date_fin`) et l'espace client Inertia datent
toujours les parts logistiques par `created_at`. Une part logistique dont la date de gain et la date de
création tombent dans deux mois différents peut donc changer de mois entre les choix de la liste
« Période » de l'écran Commissions et le filtre comptable. Un lien de l'accueil sur une période rapide
ouvre Commissions avec `date_debut`/`date_fin` : ce même écart peut s'y voir.

Une part logistique sans `earned_at` (colonne nullable) n'entre dans aucune période datée, comme sur les
fiches de paiement ; seule « Toutes les périodes » la compte.

### Une même opération peut changer de période d'un écran à l'autre

Une vente est classée par sa validation dans Ventes, et sa commission par la date de gain dans
Commissions : si ces deux dates tombent de part et d'autre du 15 ou d'une fin de mois, la vente et sa
commission apparaissent dans deux périodes différentes. Il en va de même pour un transfert (départ
réel dans Ventes, date de gain dans Commissions). C'est la conséquence de la règle « chaque module
garde sa date » ; aligner les écrans serait une décision métier.

## Montants

Aucune formule ne change. Sur la période sélectionnée, le dashboard recalcule `total_earned`,
`total_paid`, `frais_depenses_total`, `balance`, `operations_count`, `depenses_total` et `par_vehicule`.

`summary_evolution` garde sa définition : période précédente de **même durée**. Pour une quinzaine,
elle ne correspond donc pas forcément à la quinzaine précédente (P1 de novembre, 15 jours, est comparée
au 17-31 octobre). L'accueil et l'écran Commissions ne l'utilisent pas : ils n'affichent une tendance
qu'entre deux mois entiers ou deux années entières de la période comptable.

L'API de l'espace client n'expose ni le montant « en attente de validation » ni le montant « payable » :
le filtre ne les ajoute pas.

## Écran Nuxt

- Composant unique : `components/client/PeriodeComptableFilter.vue`, en tête de page, en mobile et en
  bureau. Année et mois en listes (avec « Toute l'année »), puis `Tout le mois`, `P1 : 01–15`,
  `P2 : 16–Fin`.
- Par défaut : année et mois en cours, mois entier.
- La sélection est partagée entre l'accueil et les trois écrans (`useClientPeriodeComptable`) et revient
  au mois en cours au rechargement de la page. Les années proposées sont l'année en cours et les quatre
  précédentes.
- Les bornes affichées sous le filtre sont celles renvoyées par le serveur.
- Sur Dépenses, le « Total validé » nomme sa période (`Total validé · Ce mois`,
  `Total validé · Toutes les périodes`), quel que soit le filtre qui l'a fixée.

### Accueil

Bouton « Période : … » juste avant la carte « Cumul des commissions » (`PeriodeAccueilFilter.vue`). Il
ouvre un panneau : périodes rapides, ou année, mois et quinzaine ; `Réinitialiser` revient à « Ce mois ».

Tous les montants suivent la période : cumul, déjà payé, reste à payer, dépenses, opérations, commissions
par véhicule, en mobile comme en bureau. Une période sans commission affiche « Aucune commission sur
cette période. ». La carte « Dépenses » mobile lit `total_valide` sur les bornes renvoyées par le
dashboard : c'est le même total que l'écran Dépenses sur cette période.

Les liens gardent la période : une période comptable est déjà partagée, une période rapide passe par ses
bornes (`?du=…&au=…`) et « Toutes les périodes » vers Dépenses par `?periode=toutes`. Vers Commissions,
« Toutes les périodes » n'a pas d'équivalent : le lien ouvre la période comptable en cours de sélection.

### Cohabitation avec les autres filtres de période

Aucun filtre existant n'a été retiré. Une seule période s'applique à la fois :

| Écran | Filtre existant | Effet |
|---|---|---|
| Commissions | liste « Période » (7 jours, 30 jours…) | remplace la période comptable |
| Ventes | périodes rapides et date du panneau Filtre | remplacent la période comptable |
| Dépenses | période du panneau Filtre, dont « Toutes les périodes » | remplace la période comptable |

Toucher au filtre comptable le réactive et efface l'autre période. Les filtres véhicule, statut, type et
la recherche se combinent avec lui.

### Liens qui ouvrent toutes les périodes

- `/espace-client/activite?commande=<id>` (notification) charge toutes les périodes, car l'opération
  peut dater d'un autre mois.
- `/espace-client/depenses?periode=toutes` ouvre les dépenses sur toutes les périodes. **À utiliser pour
  tout lien qui annonce un total toutes périodes**, comme la carte « Dépenses » de l'accueil mobile quand
  sa période est « Toutes les périodes ».
- Sur Dépenses, une période vide propose le bouton « Voir toutes les périodes ».
