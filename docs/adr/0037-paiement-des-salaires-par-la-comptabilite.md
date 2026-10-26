# ADR 0037 — Paiement des salaires par la Comptabilité

- **Date** : 2026-10-26
- **Statut** : accepté le 2026-10-26 — lot 0 livré le 2026-10-26 (non commité)
- **Périmètre** : paie des salariés — cf. [paie.md](../paie.md)

## Contexte

L'audit RH du 26/10/2026 a établi que :

- depuis le commit `5ef8c25f` (16/06/2026), aucune route ne faisait passer une période de paie au
  statut validé : les salaires n'étaient payables par aucun écran ;
- trois chemins de paiement coexistaient (ligne, salarié, ancienne route Paie RH), sans transaction
  ni verrou, avec des permissions différentes (`rh-paie.pay` côté serveur, `comptabilite.payer`
  pour le bouton) ;
- le détail, le paiement et les exports des salaires n'étaient pas limités aux agences de
  l'utilisateur ; une modification de contrat pouvait le rattacher à l'employé d'une autre
  organisation ou activer un second contrat.

## Décision

1. **Répartition** : RH gère les données des salariés (contrats, salaire de base, puis primes et
   avantages) ; la Comptabilité valide la période, calcule, paie et comptabilise. Il n'y a qu'un
   circuit de paie, celui de *Comptabilité → Paiement salaire*.
2. **Validation de la période** depuis cet écran, avec la permission existante `rh-paie.validate`
   (seul usage : `PaiePolicy::validate`). Aucun rôle n'est modifié : un administrateur peut
   l'accorder au rôle comptable dans *Rôles*.
3. **Paiement** : `comptabilite.payer` seule, sur les trois chemins. `rh-paie.pay` est conservée
   (des rôles la référencent, `PermissionCatalog` interdit de renommer) mais n'a plus d'effet.
4. **Un seul service de paiement** (`PaiementSalaireService`) : transaction, verrou de la ligne,
   reste relu sous verrou, contrôle explicite de l'organisation (Gate::before du super
   administrateur) et des agences d'écriture.
5. **Contrats** : un seul contrat actif par employé en création et en modification, employé limité
   à l'organisation du contrat.

## Décisions prises pour la suite (non réalisées)

- Validation des types de primes et de leurs affectations : permission donnée par défaut au seul
  super administrateur, délégable dans *Rôles* (même mécanisme que `ventes.annuler_exceptionnel`).
- Retrait de l'ancien écran Paie RH et des fiches « salariés » des Périodes (lot 5), historique
  conservé en lecture.
- Dépenses employé injectant une prime en paie : comportement inchangé tant que l'inventaire des
  données de production n'est pas fait.

## Conséquences

- Un utilisateur qui n'avait que `rh-paie.pay` ne peut plus payer ; il lui faut `comptabilite.payer`.
- Un comptable ne paie que les salariés de ses agences de rattachement ; la vision 360° permet de
  les consulter, pas de les payer.
- Aucune migration, aucune donnée modifiée.
