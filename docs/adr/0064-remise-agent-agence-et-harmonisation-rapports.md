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
   celle de sa caisse dédiée (et de son compte Mobile Money dédié). Calcul `RemiseAgentService`
   (**amendé le 15/11/2026**, règle des charges validées, décisions de l'utilisateur) :

   ```
   montant net à remettre = encaissé (espèces + Mobile Money)
                          + approvisionnements reçus de l'agence
                          − charges VALIDÉES saisies par l'agent
   reste à remettre       = montant net à remettre − déjà remis (réception confirmée par l'agence)
   ```

   - **Cumul jusqu'à la fin de la période choisie**, depuis la validation de la première caisse
     dédiée ou du premier compte Mobile Money de l'agent (`valide_le`) : avant, ses encaissements
     n'étaient pas suivis par des versements. Encaissé lundi, remis mardi → reste lundi = montant,
     reste mardi = 0 ; un report n'est jamais perdu, jamais négatif d'un jour sur l'autre.
   - **Encaissé** : espèces et Mobile Money dont il est l'auteur (les fonds qu'il détient) ; virement
     et chèque vont directement à la banque de l'agence et n'entrent pas dans le net.
   - **Charges validées seulement** : en attente, rejetées, annulées et supprimées ne sont jamais
     déduites. **Pas de double déduction** (audit du 15/11/2026) : la validation d'une dépense crédite
     la caisse de l'agence (571000, mapping `depense_interne_validee` / `tresorerie`), jamais le
     sous-compte de la caisse de l'agent — son solde n'en tient pas compte, d'où la déduction ici.
   - **Déjà remis** : versements de caisse (`interne_caisses`) et transferts Mobile Money
     (`transfert_mobile_money`) reçus par l'agence avant la fin de la période. **En transit** :
     envoyés ou contestés, non reçus à cette date — ils restent compris dans le reste (affichés à
     part). **Remis sur la période** : réceptions confirmées pendant la période.
   - **Statut** = la même règle que les agences, désormais unique : `RemisesAgencesService::statut()`
     (Non remis, Remise en cours, Partiellement remis, Totalement remis, Rien à remettre).
   - Sans caisse ni compte Mobile Money validé : « Aucune caisse dédiée », jamais un zéro.
   - **Jamais appliqué au rapport par agence** : la remise d'une agence à la trésorerie principale
     reste la règle ADR 0016 (calcul de `FinancementAgenceService`).
   - Remplace la première version du 14/11/2026 (reste = solde de caisse à ce jour, transit exclu).
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
