# Audit des filtres de période — 04/11/2026

Lot 1 du chantier « standardisation des filtres temporels ». Aucun code modifié pendant l'audit.

> **Photographie du 04/11/2026, avant migration.** Le back-office a depuis été migré : convention et
> état à jour dans [periodes.md](periodes.md), décision dans
> [ADR 0057](adr/0057-standard-unique-des-periodes.md). `SituationPeriode` est devenu
> `App\Support\Periodes\Periode`. Un calculateur supplémentaire, découvert après cet audit, a aussi
> été retiré : le modal d'export de Ventes calculait ses périodes dans le navigateur.

## 1. Synthèse

- **Un moteur existe déjà et sert de référence** : `App\Support\Vehicules\SituationPeriode`. Il porte
  les 8 périodes rapides + « Toute la période » + « Période personnalisée », résout les bornes côté
  serveur (semaine du lundi au dimanche, `subMonthNoOverflow`) et sert déjà la fiche véhicule, le
  rapport d'activité, « Ma situation », la fiche agent et 4 endpoints de l'espace client Nuxt.
- **Cinq autres calculateurs** coexistent, avec des clés et des bornes différentes (§ 3).
- **Le front Nuxt est conforme** : il n'envoie que la clé (`periode`) ou des dates, jamais de bornes
  calculées par le navigateur (`config/clientActivity.ts`).
- **Le back-office ne l'est pas** : le type `period` de `DataFilters.vue` (raccourcis + Du/Au)
  existe mais **aucune page ne l'utilise** ; 9 pages ont un `date-range` brut sans raccourcis, une
  page a ses propres raccourcis anglais, le tableau de bord calcule une partie de ses périodes dans
  le navigateur.
- **Fuseau** : `config/app.php` = `UTC`, identique à l'heure de Conakry (UTC+0, sans heure d'été).
  Aucun écart de fuseau constaté.
- **Clé « toutes les périodes »** : la clé en production est `tout` (`SituationPeriode::TOUT`,
  Nuxt `clientPeriodeComptable.ts`), pas `toutes`. Elle doit rester `tout`.

## 2. Bug constaté

| Bug | Où | Effet |
| --- | --- | --- |
| « Mois dernier » utilise `subMonth()` (débordement) | `IndexDashboardController::dateRangeForPeriode` | Les 29, 30 et 31 du mois, « Mois dernier » affiche le **mois en cours** (vérifié : le 31/10/2026 → 01/10 – 31/10 au lieu de septembre). |

## 3. Calculateurs de période

| # | Calculateur | Clés | Écart avec la référence |
| --- | --- | --- | --- |
| 1 | `SituationPeriode` (référence) | `aujourd_hui` … `annee_precedente`, `tout`, `personnalisee` ; dates `date_from`/`date_to` | Période personnalisée invalide → repli **silencieux** sur le défaut ; noms de dates différents de l'API (`date_debut`/`date_fin`) |
| 2 | `IndexDashboardController::dateRangeForPeriode` | `semaine_derniere`, `mois_dernier`, `t1`–`t4`, `s1`, `s2` en plus | Clés différentes, bug § 2, pas de `annee_precedente` |
| 3 | `IndexCommandeVenteController`, `IndexFactureVenteController` | `today`, `week`, `month`, `all`/`tout` | Clés anglaises, `whereYear`/`whereMonth` sur la colonne |
| 4 | `ClientEarningsService::periodToDates` (param `period`) | `7j`, `ce_mois`, `mois_passe`, défaut 30 jours glissants | `ce_mois` s'arrête **aujourd'hui** alors que `SituationPeriode::ce_mois` va à la fin du mois : même clé, bornes différentes |
| 5 | `EvolutionCAWidget.vue` (navigateur) | mêmes clés que le n° 2 | Bornes calculées avec l'horloge du navigateur |
| 6 | Dates brutes lues dans chaque contrôleur (§ 4) | `date_debut`/`date_fin`, `detection_debut`/`detection_fin` | Aucune validation, aucun raccourci, 3 façons d'écrire la borne de fin |

Validation API de l'espace client : correcte (422 via `prohibits` et `after_or_equal`) mais
**recopiée dans 4 requêtes** (`ActiviteMine`, `CommissionsMine`, `DashboardMine`, `DepensesMine`).
`CommandesMine`, `CommissionsParrain` et `PaiementsParrain` n'acceptent que des dates.

## 4. Inventaire back-office

Conformité : **C** conforme · **P** partiel (dates seules ou calcul local) · **N** non conforme ·
**M** période métier à conserver · **—** pas de filtre.

### 4.1 Périodes calendaires (à migrer vers le standard)

| Module | Page | Contrôleur / service | Filtre actuel | Colonne | Calcul actuel | Conf. | Action |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Tableau de bord | `Dashboard.vue` (HeaderWidget) | `Divers/IndexDashboardController` | select serveur | `created_at` | calculateur n° 2 | N | Résolveur central + correction bug |
| Tableau de bord | `EvolutionCAWidget.vue` | — (navigateur) | select local | — | navigateur | N | Le serveur fournit les bornes |
| Ventes | `Ventes/Index.vue` | `Ventes/IndexCommandeVenteController` | `date-range` + `periode` caché (`all`) | `created_at` | `whereDate` + calculateur n° 3 | P | Champ `period` |
| Ventes (export) | — | `Ventes/ExportCommandeVenteController` | dates seules | `created_at` | `whereDate` ; **ignore `periode`** | P | Même résolution que la liste |
| Factures | `Factures/Index.vue` | `Ventes/IndexFactureVenteController` | select `today/week/month/tout` | `created_at` | calculateur n° 3, défaut `month` | N | Champ `period`, défaut `ce_mois` |
| Logistique | `Logistique/Index.vue` | `TransfertLogistiqueController` | `date-range` | `created_at` | `whereDate` | P | Champ `period` |
| Dépenses (liste, CSV, impression) | `Depenses/Index.vue` | `DepenseListingService` | `date-range` | `date_depense` | `>=`/`<=` (cohérent sur les 3 sorties) | P | Champ `period` |
| Cashback | `Comptabilite/Cashback/Index.vue` | `CashbackController` (2 requêtes) | `date-range` | `created_at`, `date_depense` | `whereDate` | P | Champ `period` |
| Communications | `Communications/Index.vue` | `Divers/IndexCommunicationController` | `date-range` | `created_at` | concat `' 23:59:59'` | P | Champ `period` |
| Historique des actions | `Comptabilite/Historique.vue` | `HistoriqueActionsController` (2 requêtes) | `date-range` | `created_at` | concat `' 23:59:59'` | P | Champ `period` |
| Propositions véhicule | `Vehicules/Propositions/Index.vue` | `PropositionVehiculeController` | `date-range` | `created_at` | `whereDate` | P | Champ `period` |
| Contrôle des encaissements | `ControleEncaissements/Index.vue` | `ControleEncaissementsController` | `date-range` « Détectée entre » | `derniere_detection_le` | `startOfDay`/`endOfDay`, date invalide ignorée | P | Champ `period` |
| Monitoring commissions | `CommissionMonitoring/Index.vue` | `IndexCommissionMonitoringController` | `date-range` « Détectée entre » | dérivée (en PHP) | idem | P | Champ `period` |
| Situation véhicule | `Vehicules/Show.vue` (SituationEntete) | `VehiculeController` + `SituationPeriode` | composant dédié | `validated_at` sinon `created_at` | référence | C | Renommer `date_from`/`date_to` (alias conservés) |
| Fiche agent | `Users/Show.vue` (SituationEntete) | `ShowUserController` + `SituationPeriode` | composant dédié | rapport | référence | C | idem |
| Rapport d'activité / Ma situation | `Rapports/Activite.vue` | `RapportPerimetreResolver` (+ export) | select + dates | rapport | référence, sous-ensemble de clés | C | idem |
| Espace client (Inertia) | `client/Earnings.vue`, `client/VehicleBalanceDetail.vue` | `ClientEarningsService` | `period` + dates | — | calculateur n° 4 | N | Voir § 6 |

### 4.2 Périodes métier (à conserver, ne pas remplacer par des périodes calendaires)

| Page | Contrôleur | Paramètres | Règle |
| --- | --- | --- | --- |
| Commissions Livreurs/Propriétaires/Agences/Consultants (+ détails) | `CommissionVente/Proprietaire/Site/ConsultantController`, `CommissionDetailFilters` | `periode[]` = codes `AAAA-MM-P1/P2/M` | `CommissionPeriodeFilter`, union de quinzaines (décision du 18/10) |
| Ajustements véhicule | `CommissionAjustementController` | `periode` = code | idem |
| Périodes de paiement, Fiches | `PaiementPeriodeController`, fiches | `annee`, `mois`, `quinzaine` | `PeriodeComptableService` |
| Salaires, Paie, Transmissions de paie | `SalaireController`, `PaieController`, `TransmissionPaieController` | `annee`, `mois` | période de paie |
| Journal financier | `JournalFinancierController` | `annee`, `mois` | mois comptable (`whereYear`/`whereMonth` à remplacer par des bornes, sans changer la règle) |
| Remises agences, Financement agence | `RemisesAgencesController`, `FinancementAgenceController` | `annee`, `mois` | mois comptable |
| Situation de trésorerie | `SituationTresorerieController` | `date` (situation à date) | date unique, pas une période |

### 4.3 Pages de liste sans filtre temporel mais avec des données datées

| Page | Contrôleur | Colonne candidate |
| --- | --- | --- |
| `Achats/Index.vue` (bons de commande) | `Achats/IndexCommandeAchatController` | `created_at` |
| `Achats/Factures/Index.vue` | `Achats/Factures/IndexFactureFournisseurController` | `date_facture` |
| `Logistique/ReceptionsFournisseurs.vue` | `Achats/Receptions/IndexReceptionAchatController` | `validee_at` |
| `Comptabilite/MouvementsFonds/Index.vue` | `MouvementFondsController` | `created_at` |
| `Packings/Index.vue` | `PackingController` | `date` |
| `Comptabilite/Tresorerie/InterAgences/Index.vue` | `InterAgencesController` | à confirmer (liste de soldes) |

Les autres pages de liste (produits, clients, véhicules, rôles, paramètres…) affichent des
référentiels : pas de filtre de période.

## 5. API espace client et parrain (Nuxt, mobile)

| Endpoint | Requête | `periode` | Période comptable | Dates | Colonne |
| --- | --- | --- | --- | --- | --- |
| `GET /v1/mobile/dashboard` | `DashboardMineRequest` | oui | oui | via `period` (n° 4) | selon bloc |
| `GET /v1/mobile/activite` | `ActiviteMineRequest` | oui | oui | oui | `validated_at`/`date_depart_reelle` sinon `created_at` |
| `GET /v1/mobile/commissions` | `CommissionsMineRequest` | oui | oui | oui | `earned_at` |
| `GET /v1/mobile/depenses/mine` | `DepensesMineRequest` | oui | oui | oui | `date_depense` (`whereDate`) |
| `GET /v1/mobile/commandes/mine` | `CommandesMineRequest` | non | non | oui | `validated_at` (`whereDate`) |
| `GET /v1/parrain/commissions` | `CommissionsParrainRequest` | non | non | oui | — |
| `GET /v1/parrain/paiements` | `PaiementsParrainRequest` | non | non | oui | `date_paiement` (`whereDate`) |

## 6. Constats transverses

1. **Repli silencieux** : sur le back-office, une période personnalisée invalide (Du > Au, date
   impossible) renvoie le défaut ou une liste vide sans message ; l'API renvoie 422.
2. **Fonctions SQL sur les colonnes** (`whereDate`, `whereYear`, `whereMonth`) dans Ventes, Factures,
   Logistique, Cashback, Propositions, Journal, API Dépenses/Commandes/Paiements parrain : l'index de
   la colonne n'est pas utilisé.
3. **Libellés** : back-office « Semaine précédente / Mois précédent / Année précédente / Toute la
   période », Nuxt « Semaine dernière / Mois dernier ». Le cahier des charges retient « Semaine
   dernière, Mois dernier, Année dernière, Toutes les périodes ».
4. **Défauts par module** (à conserver, règle « aucune sélection = défaut du module ») : Ventes
   `tout`, Factures `ce_mois` (aujourd'hui `month`), Tableau de bord `ce_mois`, Rapport
   `aujourd_hui`, Situation véhicule `tout`, autres listes `tout`.
5. **Param `period` legacy** (`7j`, `30j` glissants, `mois_passe`) : fenêtres glissantes absentes du
   standard, encore acceptées par `/v1/mobile/dashboard` et les pages client Inertia.

## 7. Architecture cible proposée

- `App\Support\Periodes\Periode` : objet normalisé (`cle`, `debut` inclus, `finExclue` = lendemain de
  la date de fin à 00:00, `libelle()`, `pourFront()`), avec `appliquer($query, $colonne)` en bornes
  semi-ouvertes (`>= debut`, `< finExclue`), sans fonction SQL sur la colonne.
- `App\Support\Periodes\PeriodeResolver` : seul calculateur. `SituationPeriode` en devient une façade
  (même API publique) pour ne casser aucun appelant.
- `App\Support\Periodes\ReglesPeriode` : règles de validation communes (`periode` ∈ clés,
  `date_debut` + `date_fin` ensemble, `date_fin >= date_debut`, `periode` exclusif des dates),
  réutilisées par les FormRequest API et back-office.
- Paramètres standard : `periode` + `date_debut`/`date_fin` ; `date_from`/`date_to`, `today/week/
  month`, `semaine_derniere`, `mois_dernier` restent acceptés en alias (liens et vues enregistrées).
- Périodes métier inchangées : `PeriodeComptableFiltre`, `CommissionPeriodeFilter`,
  `PeriodeComptableService`.
- Front : type `period` de `DataFilters.vue` partout, options fournies par le serveur
  (`Periode::options()`).
- Contrôle CI : test d'architecture PHPUnit (aucun `startOfWeek/subMonth…` ni `whereDate` de filtre
  dans `app/Http/Controllers` hors liste blanche ; registre des endpoints datés couverts par un test
  de bornes) + règle dans `scripts/check-ui-standards.mjs` (pas de `date-range` pour une période, pas
  de calcul de période avec `new Date()` dans `resources/js/pages|components`).
