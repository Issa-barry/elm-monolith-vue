# ADR 0063 — Rapport d'activité par agence et remise à la trésorerie principale

- **Date** : 2026-11-13
- **Statut** : accepté
- **Périmètre** : Rapports (nouvel écran « Rapport par agence »), vue « Remises des agences »
  (libellés de statut), filtres de période de tous les rapports
- **Liens** : [ADR 0007](0007-rapport-activite-perimetre-et-regles-de-calcul.md) (rapport d'activité),
  [ADR 0012](0012-encaissement-inter-agences-et-reglement.md) (agence d'encaissement),
  [ADR 0016](0016-remise-au-siege-et-position-des-agences.md) (remise à la trésorerie principale),
  [ADR 0057](0057-standard-unique-des-periodes.md) (périodes) — documentation :
  [rapports.md](../rapports.md), [periodes.md](../periodes.md)

## Contexte

Le métier dispose de « Ma situation » (un agent) et du rapport d'activité (agences et agents). Il veut
une vue consolidée **par agence** : encaissements par moyen (espèces, Orange Money…), ventes, dettes
clients, et surtout ce que chaque agence a **réellement remis** à l'agence principale (trésorerie
principale, ADR 0017), avec le reste et un statut Non remis / Partiellement remis / Totalement remis.

La demande relayée proposait « reliquat = encaissé − reversé ». Cette formule contredit l'ADR 0016 :
une agence conserve ses obligations locales (commissions, propriétaires, salaires, factures
fournisseurs) et ne remet que les fonds d'autres agences et son excédent ; et l'écran « Remises des
agences » interdit déjà « attendu − remis » (une remise baisse le reste dès son envoi, la soustraire
encore la compterait deux fois). Contradiction signalée à l'utilisateur le 13/11/2026.

## Décision (validée par l'utilisateur le 13/11/2026)

1. **Règle ADR 0016 conservée** pour la colonne « Remis à l'agence principale » :
   - **Remis** = remises **reçues** par la trésorerie principale pendant la période (mouvement de
     fonds agence → trésorerie principale, « Transfert entre agences » ou « Règlement inter-agences »,
     statut Reçu, date de réception dans la période) ;
   - **En transit** = remises envoyées, pas encore confirmées (jamais « remis ») ;
   - **Reste à remettre** = calcul du moment de `FinancementAgenceService` (`total_a_remettre`),
     quelle que soit la période ;
   - un encaissement n'est **jamais** un montant remis ; un financement (trésorerie principale →
     agence) n'est jamais une remise ; la trésorerie principale ne se remet rien (« Trésorerie
     principale »).
   Les chiffres sont **identiques** à ceux de « Remises des agences » : un seul calcul,
   `RemisesAgencesService`, appelé avec les bornes de la période du rapport.
2. **Libellés de statut renommés sur les deux écrans** (clés techniques inchangées) : `a_remettre` →
   « Non remis », `remis` → « Totalement remis » ; « Partiellement remis », « Remise en cours » (argent
   en transit) et « Rien à remettre » sont conservés. Le statut « À remettre » de l'écran Financement
   (`StatutFinancementAgence`) est une autre notion et ne change pas.
3. **Consolidation, pas de second moteur** : ventes, encaissements par moyen réellement utilisé et
   dettes clients d'une agence sont les résumés de `RapportActiviteService` avec un périmètre réduit à
   cette agence — mêmes axes que le rapport d'activité (ventes et dettes : agence de la commande ;
   encaissements : agence qui a reçu l'argent, ADR 0012). `RapportAgencesService` assemble les lignes.
4. **Écran** : Rapports → « Rapport par agence » (`/backoffice/rapports/agences`), permission
   `rapports.read` et périmètre d'agences du rapport d'activité (`RapportPerimetreResolver`), pas de
   filtre Agent (la remise est une donnée d'agence). Export Excel. « Opérations » ouvre le rapport
   d'activité de l'agence sur la même période ; « Remises » ouvre le détail de « Remises des agences »
   (seulement avec `tresorerie.read` et le module Comptabilité).
5. **Périodes de tous les rapports** : Aujourd'hui, Hier, Cette semaine, Ce mois, **Mois dernier**,
   **Date précise**, Période personnalisée. « Date précise » n'est pas un troisième mode (ADR 0057 :
   deux modes) : l'écran envoie `date_debut` = `date_fin` ; le serveur renvoie la clé `date_precise`
   pour l'afficher (`Periode::DATE_PRECISE`, `cleAffichee()`), uniquement aux écrans qui la proposent.
6. **Charges par agence** (ajout du 14/11/2026, validé par l'utilisateur) : dépenses de l'agence
   (`depenses.site_id`, `date_depense` dans la période). **Payées = validées** — la validation d'une
   dépense passe aussitôt la sortie de trésorerie, il n'existe pas de « validée non payée » ;
   **en attente = soumises**, montrées à part ; **dont avances sur tiers** (Véhicule, Propriétaire,
   Livreur, Parrain : avances récupérables, pas des charges de l'entreprise), liste partagée avec la
   comptabilisation (`DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS`, testée). Brouillons,
   rejetées, annulées et supprimées exclues. Jamais déduites du remis ni du reste à remettre.

7. **Encaissements par moyen** (ajout du 14/11/2026, validé par l'utilisateur) : groupe « Encaissements
   de la période » = **Espèces** et **Orange Money** toujours affichés, puis **Total encaissé** ; Virement,
   Chèque et les autres opérateurs n'ont de colonne que s'ils ont été réellement encaissés sur la
   période (le total ne cache jamais d'argent reçu). **Total encaissé = somme des colonnes de
   moyens**, jamais un montant tiré des ventes. Mêmes colonnes dans l'export Excel. Aucun moyen de
   paiement ni encaissement modifié.

## Conséquences

- Les responsables lisent sur un seul écran l'encaissé, le reçu par la trésorerie principale, l'argent
  en transit et le reste, agence par agence, sans écart possible avec « Remises des agences ».
- Une remise reçue en dehors de la période choisie n'apparaît pas en « Remis » ; le reste, lui, est
  toujours celui du moment (même règle que la vue des remises).
- « Remis » ne distingue pas encore la part « fonds d'autres agences » / « excédent » d'une remise :
  elle dépend du lot 2 de l'ADR 0016 (bouton de remise, frais), non développé.
