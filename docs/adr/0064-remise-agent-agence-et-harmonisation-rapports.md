# ADR 0064 — Remise de l'agent à son agence et harmonisation des rapports

- **Date** : 2026-11-14
- **Statut** : accepté
- **Périmètre** : Rapports › Rapport d'activité, Rapports › Ma situation (exports compris)
- **Liens** : [ADR 0016](0016-remise-au-siege-et-position-des-agences.md) (agence → trésorerie
  principale), [ADR 0018](0018-approvisionnement-caisse-agent.md) et ADR 0027 (caisse et compte Mobile
  Money dédiés), [ADR 0063](0063-rapport-activite-par-agence.md) (rapport par agence, référence
  graphique) — documentation : [rapports.md](../rapports.md)

## Contexte

Le Rapport par agence (ADR 0063) montre ce qu'une agence a remis à la trésorerie principale. Le métier
veut le même langage graphique pour le Rapport d'activité et Ma situation, avec, au niveau des agents :
encaissements Espèces / Orange Money, charges saisies et **remise à l'agence**. La trésorerie a deux
niveaux de remise, qui doivent rester traçables séparément :

```
Agent  ── versements de caisse / transferts Mobile Money ──▶  son agence de rattachement
Agence ── remises (transfert entre agences, règlement)     ──▶  trésorerie principale (Matoto)
```

## Décision

1. **Un agent remet à SON agence**, jamais à la trésorerie principale. Son agence de rattachement est
   celle de sa caisse dédiée (et de son compte Mobile Money dédié). La remise est lue dans les vraies
   opérations existantes — versements de caisse (`interne_caisses`) et transferts Mobile Money
   (`transfert_mobile_money`) —, via `FicheCaisseService` (grand livre), jamais un encaissement :
   - **remis** = versements dont l'agence a **confirmé la réception** pendant la période
     (`recus_periode`, même définition que le « remis » d'une agence, ADR 0016) ;
   - **en transit** = envoyés ou contestés, réception pas encore confirmée, à ce jour ;
   - **reste à remettre** = solde à ce jour de la caisse et du compte Mobile Money (il baisse dès
     l'envoi : jamais « encaissé − remis ») ;
   - **statut** = la même règle que les agences, désormais unique : `RemisesAgencesService::statut()`
     (Non remis, Remise en cours, Partiellement remis, Totalement remis, Rien à remettre) ;
   - sans caisse ni compte Mobile Money : « Aucune caisse dédiée », jamais un zéro.
2. **Charges saisies par l'agent** = dépenses dont il est l'auteur (`depenses.user_id`), validées
   (payées) **ou** en attente de validation, dans les agences du périmètre ; jamais les charges de son
   agence (`site_id`, rapport par agence). Un seul calcul : `ChargesRapportService` (axes agence et
   agent), mêmes exclusions (brouillons, rejetées, annulées, supprimées).
3. **Moyens d'encaissement** : mêmes colonnes que le rapport par agence (Espèces, Orange Money, et tout
   autre moyen seulement s'il a été encaissé), total encaissé = somme des colonnes —
   `RapportActiviteService::colonnesMoyens()`, partagée par les trois rapports.
4. **Rapport d'activité** : une ligne par agent (`RapportActiviteService::parAgent()`, requêtes
   regroupées sur les mêmes bases que les blocs — la somme des lignes redonne les totaux). Cartes :
   Ventes, Total encaissé, Dettes clients, Charges saisies, Remis à l'agence. Le détail (ventes,
   encaissements, dettes, Mobile Money, caisses, charges) reste en onglets ; « Opérations » filtre la
   page sur l'agent.
5. **Ma situation** : six cartes (Mes ventes, Mes encaissements, Mes dettes clients, Mes charges
   saisies, Remis à mon agence, Reste à remettre à mon agence), « Mes encaissements » par moyen avec
   total, « Remises à mon agence » (remis, en transit, reste, statut), onglet Mes charges avec la liste
   et le lien vers la dépense (seulement avec `depenses.read` et le module Dépenses).
6. **Exports** Excel et PDF : moyens et total encaissé, charges saisies, remise à l'agence, feuille /
   tableau « Agents » (rapport d'activité) et liste des charges.

## Conséquences

- Les trois écrans partagent la même identité (cartes `KpiCard compact`, tableau, groupe « Remis »
  sur fond distinct, statuts `StatusDot`) ; seuls le niveau (agence ou agent) et la destination de la
  remise changent.
- Ma situation et le rapport d'activité filtré sur un agent donnent les mêmes chiffres (même moteur).
- Le Rapport par agence n'a pas changé de comportement : ses charges et ses colonnes de moyens
  passent seulement par les mêmes services partagés.
- Un agent qui détient des caisses dans deux agences a une remise agrégée sur ces agences (leurs noms
  sont affichés) ; cas non rencontré à ce jour.
