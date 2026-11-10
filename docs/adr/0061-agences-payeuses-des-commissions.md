# ADR 0061 — Agences payeuses des commissions : politique configurable

- **Date** : 2026-11-07
- **Statut** : **accepté le 2026-11-07** (décision de l'utilisateur)
- **Périmètre** : fiches de paiement des commissions (propriétaire, consultant, parrain, site, livreur),
  écrans Commissions (Propriétaires, Livreurs, Sites) et leurs exports, liste des fiches, droits de
  lecture et de paiement d'une fiche, Financement / Remises des agences, comptabilité des fiches
- **Amende** : [ADR 0020](0020-agence-de-paiement-des-commissions-site-du-vehicule.md) (l'agence du
  véhicule devient l'agence de **rattachement** ; elle ne paie plus que si la politique le prévoit)

## Contexte

Depuis l'ADR 0020, l'agence qui paie une commission livreur, propriétaire ou parrain est le site actuel
du véhicule ; une commission Site est payée par le site lui-même ; un consultant, sans agence, est payé
par la trésorerie principale. En production (écran Commission propriétaire, septembre 2026 :
39 propriétaires, 39 878 200 GNF restant à payer), un propriétaire comme Moussa SIDIBE a 17 véhicules
dans 6 agences : sa fiche était payée par l'agence la plus lourde en montant.

L'utilisateur décide que **Matoto paie tous les bénéficiaires sauf les livreurs**, et demande que cette
politique se règle dans les Paramètres, sans intervention technique.

## Décision

1. **Politique par organisation** (Paramètres → Agences payeuses, permission
   `commissions.configurer_agences_payeuses`, super administrateur par défaut). Par catégorie de
   bénéficiaire, trois règles :
   - **Agence centralisée** : une seule agence, commune à toutes les catégories réglées ainsi. Vide =
     la trésorerie principale (ADR 0017). La changer déplace d'un coup toutes ces catégories.
   - **Agence de rattachement** : l'agence du véhicule (livreur, propriétaire, parrain — ADR 0020), le
     site bénéficiaire (Site), aucune pour un consultant (trésorerie principale au décaissement).
   - **Autre agence** : une agence choisie pour cette seule catégorie.
2. **Règles par défaut** (sans configuration enregistrée) : propriétaires, consultants, parrains et
   sites → agence centralisée ; livreurs → agence de rattachement (inchangé).
3. **Deux agences par fiche** : `site_id` = l'agence qui **paie** ; `site_rattachement_id` = l'agence
   de rattachement. Une fiche n'a toujours qu'une agence payeuse (ADR 0020, point 4).
4. **Lecture et paiement** : les responsables de l'agence de rattachement gardent la **lecture**
   (écrans Commissions, liste et détail des fiches) ; seuls ceux de l'agence payeuse (ou les comptes
   « toutes agences ») voient et utilisent **Payer**. Les responsables de l'agence payeuse d'une
   catégorie entière voient toutes ses commissions.
5. **Changement de politique** : les fiches non entièrement payées passent aussitôt à leur nouvelle
   agence payeuse, avec la réaffectation comptable de l'ADR 0020 (reste dû et charge déplacés). Les
   fiches payées ne bougent pas ; un paiement déjà fait garde son agence. Chaque changement est
   historisé (avant, après, auteur, nombre de fiches déplacées).
6. **Véhicule réaffecté** : l'agence de rattachement suit le véhicule ; l'agence payeuse ne change que
   si la règle de la catégorie est « agence de rattachement ». **Trésorerie principale transférée** :
   sans agence centralisée choisie, les fiches non payées des catégories centralisées la suivent
   (`Site::booted()`).
7. **Garde-fous** : une agence configurée doit appartenir à l'organisation ; « agence centralisée »
   sans trésorerie principale exige de choisir l'agence centralisée. Une agence configurée devenue
   introuvable retombe sur l'agence de rattachement.

## Mise en œuvre

- `AgencePayeuseCommissionService` : politique (`commission_politiques_paiement`), défauts,
  `sitePayeur()`, `perimetreLecture()`, `enregistrer()` (historique
  `commission_politique_paiement_historique`).
- `PeriodeCalculatorService::creerFiche()` : rattachement calculé comme avant, agence payeuse depuis
  la politique (les fiches salarié ne sont pas concernées).
- `FicheSiteResponsableService` : `sitesAttendus()`, `realigner()`, `realignerAgencesPayeuses()` ;
  `changerSite()` reste le seul point qui change l'agence payeuse.
- `PaiementFichePolicy::view` accepte l'agence de rattachement, `payer` non ;
  `PaiementFiche::scopeLisiblesPourSites()` pour la liste des fiches.
- Comptabilité : mappings `fiche_reaffectee_sortie/entree` ajoutés pour les sites (467170/622300) et
  les consultants (467180/622400).
- Reprise : `site_rattachement_id` initialisé avec l'agence actuelle de chaque fiche ; la bascule des
  fiches existantes se fait par `php artisan commissions:realigner-sites-fiches` (aperçu), puis
  `--appliquer`.

## Conséquences

- Financement et Remises : le besoin « propriétaires », « parrains », « sites » et « consultants »
  est compté à l'agence centralisée ; les autres agences remettent davantage.
- La charge de ces commissions est portée en comptabilité par l'agence qui les paie (principe de
  l'ADR 0020).
- Tests : `tests/Feature/Comptabilite/AgencesPayeusesCommissionsTest.php`.
