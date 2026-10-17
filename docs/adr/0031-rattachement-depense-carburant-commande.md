# ADR 0031 — Rattachement d'une dépense de carburant à sa commande

- **Date** : 2026-10-16
- **Statut** : accepté et livré le 2026-10-16
- **Périmètre** : Dépenses (saisie, modification, soumission, liste, détail) et types de dépense
- **Doc métier** : [depenses-carburant-commande.md](../depenses-carburant-commande.md)

## Contexte

Une dépense de carburant n'était reliée à rien d'autre que son véhicule : impossible de savoir
quel voyage (vente, distribution, transfert) le plein avait servi. Une proposition externe
demandait de retrouver la commande à partir du véhicule, de la date et d'un code abrégé saisi par
l'agent. Confrontée au code :

- le format réel est `PRÉFIXE-JJMMAA-NNN` (et pas `CMD-JJMMAA-NNNN`), avec un compteur **par
  préfixe** : un même `JJ-NNN` désigne jusqu'à trois références le même jour ;
- la date d'une référence est celle de la **création** de la commande, pas du voyage (précommandes
  livrées le lendemain) : exiger l'égalité avec la date de la dépense en ferait échouer ;
- le type « carburant » est configurable par organisation et son code dérive du libellé (en base
  locale : `carburant`, pas `carburant_vehicule`) : un code figé dans le code serait fragile ;
- un second circuit carburant existe sur la fiche véhicule (`vehicule_frais`).

## Décision

1. **Une commande de référence par dépense** (vente/distribution **ou** transfert), pas de
   répartition du coût entre les commandes d'une tournée.
2. **Exception motivée** autorisée quand il n'y a pas de commande (plein de début de semaine,
   retour à vide, garage), validée par le **valideur habituel** — pas de circuit parallèle —, et
   signalée « Sans commande » dans la liste et le détail.
3. Commandes **annulées exclues** (`annulee`, `annulee_erreur_saisie`, transfert `annule`).
4. **Module Dépenses seulement** ; les frais de la fiche véhicule ne sont pas concernés.
5. **Historique intact** : aucune dépense existante modifiée ; seules les nouvelles saisies (et les
   anciens brouillons au moment de leur soumission) sont soumises à la règle.
6. La règle est portée par un **réglage du type** (`depense_types.commande_obligatoire`, catégorie
   Véhicule uniquement), initialisé par migration pour les types dont le code commence par
   `carburant`, plutôt que par un code de type figé.
7. Choix par un **champ de recherche** toujours visible, à la manière de la recherche globale
   (résultats sous le champ dès le focus, filtrés pendant la saisie par référence, code abrégé
   `JJ-NNN` ou client) — retenu le 17/10 après deux essais écartés à l'usage : champ du code +
   bouton « Rechercher », puis liste déroulante à ouvrir avant de pouvoir chercher. Le système ne
   choisit jamais à la place de l'agent.
8. Rattachables = du **véhicule**, du **jour** de la dépense (créée, livrée ou partie ce jour-là)
   et de l'**agence** de la dépense (vente de l'agence, transfert dont elle est source ou
   destination), non annulées — précisé le 16/10 par l'utilisateur ; une première version
   acceptait toute commande des 31 jours précédents. Le serveur repose ces critères à chaque
   enregistrement : la liste et le contrôle décrivent la même règle.

## Conséquences

- Nouvelles colonnes `depenses.commande_vente_id`, `depenses.transfert_logistique_id`,
  `depenses.motif_sans_commande` et `depense_types.commande_obligatoire` (migration non
  destructive).
- Une organisation dont le type carburant porte un autre nom doit cocher « Commande obligatoire »
  dans Dépenses → Types.
- Un plein acheté un autre jour que celui de la commande (veille du départ, plein pour plusieurs
  jours) ou par une autre agence ne trouve pas sa commande : il passe par l'exception motivée, ou
  la date/l'agence de la dépense est corrigée.
- La comptabilisation des dépenses véhicule (déduction de la commission du propriétaire) est
  inchangée.
