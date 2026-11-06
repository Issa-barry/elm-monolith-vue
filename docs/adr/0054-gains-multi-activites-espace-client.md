# ADR 0054 — Gains multi-activités de l'espace client

- **Date** : 2026-11-03
- **Statut** : accepté (audit et décisions de l'utilisateur du 03/11/2026, D1 à D5)
- **Périmètre** : `GET /v1/mobile/dashboard`, nouveaux `GET /v1/mobile/commissions` et
  `GET /v1/mobile/vehicules/rattaches`, `GET /api/auth/me` (`consultant_ids`), espace client Inertia
  (même moteur), écrans Accueil, Commissions et Véhicules de l'espace client Nuxt — cf.
  [api-espace-client-contract.md](../api-espace-client-contract.md) §4 et §5

## Contexte

Une même personne peut être propriétaire de véhicules, livreur, parrain et consultant. L'audit du
03/11/2026 (scénario reproduit en test) a montré que l'accueil et l'écran Commissions ne lisaient que
les parts `proprietaire` et `livreur` de `ClientEarningsService` :

- les parts du **parrain** n'apparaissaient que dans l'espace parrain, celles du **consultant** nulle
  part dans l'espace client ;
- une part **annulée** gardait son montant et restait comptée dans le cumul et le reste à payer ;
- le « reste à payer » ne déduisait que les frais de véhicule, alors que les fiches de paiement
  déduisent aussi les avances du livreur et les dépenses imputées au propriétaire, au parrain ou au
  consultant (`PeriodeCalculatorService`) ;
- un véhicule parrainé n'appartenant pas au compte n'apparaissait dans aucune liste ;
- rien ne disait à l'utilisateur au titre de quelle activité il avait gagné un montant.

## Décision

1. **Toutes les activités, chaque part une seule fois.** Les bénéficiaires du compte
   (`BeneficiairesCompte`) réunissent `ClientIdentityResolver` (propriétaire, livreur) et
   `ActivitesCompteResolver` (parrain, consultants, rattachement posé au back-office). Toute lecture part
   de ces bénéficiaires : chaque part compte dans l'activité de son `beneficiaire_type`. La part du
   parrain étant prise sur l'enveloppe Livreur (PARRAIN-007), on additionne des parts, jamais des
   enveloppes : aucun doublon possible.
2. **Origine lue sur la part.** L'activité d'une commission (`ActiviteCompte` : `proprietaire`,
   `livreur`, `parrain`, `consultant`) vient du type de bénéficiaire enregistré, jamais des rôles du
   compte. Un consultant affiche son entreprise (`Prestataire::nom_complet`).
3. **Annulées hors des montants, visibles dans l'historique.** Une part `annulee` sort de tous les
   totaux, mais reste listée avec son statut (détail des commissions, relevé Inertia).
4. **Reste à payer aligné sur les fiches.** Par activité : gains sortis de « Créée », moins les dépenses
   validées que la fiche du bénéficiaire déduit sur la période, moins le déjà versé, jamais sous 0, puis
   additionné. `en_attente_validation` expose la part encore « Créée ». Filtré sur un véhicule, seules
   les dépenses de ce véhicule se déduisent.
5. **Répartition par activité.** `par_activite` (dashboard, résumé du détail) donne les mêmes champs que
   `summary` pour chaque activité du compte ; leur somme est `summary`. Seule la page Commissions
   l'affiche, à partir de deux activités (retirée de l'accueil le 04/11/2026) ; le badge d'origine
   reste toujours présent ailleurs (véhicules, accueil). Depuis le 05/11/2026, la section reprend la
   présentation de « Gains par véhicule » : gain décroissant, part du total et barre, sans badge ni
   reste à payer par activité à l'écran (`balance` reste renvoyé par l'API).
6. **Détail des commissions** : `GET /v1/mobile/commissions`, paginé, mêmes périodes que l'accueil,
   filtres véhicule, activité, statut et recherche (référence, nom ou immatriculation du véhicule).
   **Révision du 05/11/2026 (demande de l'utilisateur)** : la liste « Détail des commissions » est
   retirée de la page Commissions de l'espace client Nuxt, en mobile et au bureau. L'endpoint reste en
   place et n'a plus de consommateur à l'écran : le front Nuxt n'affiche donc plus les parts annulées
   (point 3), ni l'origine ligne par ligne, ni les lignes citées en D2 et D3 ; la page garde la
   répartition par activité et « Gains par véhicule ».
7. **Véhicules rattachés** : propriétaire, livreur de l'équipe actuelle, parrain ; un véhicule par
   ligne avec tous ses liens (`liens`). Nouveau `GET /v1/mobile/vehicules/rattaches` ;
   `vehicules/mine` reste inchangé pour l'application mobile.
8. **Droits par lien, lecture seule** (décisions du 03/11/2026) :
   - **D1** — un véhicule seulement parrainé est en lecture restreinte : nom et immatriculation,
     prénom et nom du propriétaire, les parts et la rémunération du parrain. Jamais le téléphone,
     l'e-mail ou un identifiant du propriétaire, ni l'équipe, ni les ventes, ni les dépenses ;
   - **D2** — un véhicule dont le livreur a quitté l'équipe sort de « Mes véhicules » ; ses
     commissions restent dans le détail et dans « Commissions par véhicule » sur la période ;
   - **D3** — le consultant voit le véhicule sur ses lignes de commission, pas dans « Mes véhicules » ;
   - **D4** — le livreur garde la lecture des frais du véhicule de son équipe (inchangé) ;
   - **D5** — un compte seulement parrain reste redirigé vers l'espace parrain.
   Les écrans Ventes et Dépenses gardent les véhicules du propriétaire et du livreur
   (`vehiculesAccessibles`) : un parrainage n'y ouvre rien.

Aucune formule du moteur de commissions, aucune règle de paiement ni de comptabilité ne change : seul
le service de lecture de l'espace client (`ClientEarningsService`) est modifié.

## Conséquences

- Les montants de l'accueil changent pour les comptes concernés : ajout des parts parrain et
  consultant, retrait des annulées, reste à payer réduit des avances et dépenses déduites par les
  fiches. `depenses_total` devient égal à `frais_depenses_total`.
- L'espace client Inertia suit (même moteur, tests de parité) et reçoit `earnings_by_activite`.
- `operations_count` compte des parts : une opération où le compte est propriétaire et livreur compte
  deux fois, une fois par activité.
- Les parts du moteur logistique historique (avant le 03/09/2026, 0 ligne en production) restent dans
  les totaux du dashboard, pas dans le détail.
- `GET /gains/mine` et `vehicules/{id}/commissions` (application mobile) ne sont pas modifiés : ils
  restent limités au propriétaire et au livreur, et `vehicules/{id}/commissions` aux ventes.
- Tests : `tests/Feature/Api/Client/GainsMultiActivitesTest.php` (scénario de référence),
  `DashboardControllerTest` (avance du livreur désormais déduite), côté Nuxt
  `tests/e2e/commissions-activites.spec.ts`.

## Alternatives écartées

- **Additionner les enveloppes** plutôt que les parts : compterait deux fois la part du parrain.
- **Ajouter les véhicules parrainés à `vehiculesAccessibles`** : ouvrirait au parrain les ventes (avec
  les noms des clients) et les dépenses d'un véhicule tiers.
- **Un cumul parrain séparé sur l'accueil** : deux cumuls à rapprocher, risque de lecture en double ;
  la carte « Espace parrain » reste un lien.
- **Déduire l'activité des rôles du compte** : faux dès qu'un compte cumule plusieurs activités.
