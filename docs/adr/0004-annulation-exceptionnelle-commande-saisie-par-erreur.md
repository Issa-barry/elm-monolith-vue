# ADR 0004 — Annulation exceptionnelle d'une commande saisie par erreur, confirmation paramétrable

- **Date** : 2026-09-24
- **Statut** : accepté et livré le 2026-09-24
- **Périmètre** : Ventes (`CommandeVente`), encaissements, trésorerie, stock, commissions, cashback —
  cf. [annulation-exceptionnelle.md](../annulation-exceptionnelle.md)

## Contexte

Des commandes ont été saisies en production par des employés qui se croyaient sur l'environnement de
formation : confirmées, chargées, facturées, parfois encaissées. Aucune vente réelle. L'annulation
normale s'arrête avant le départ du véhicule et avant tout encaissement ; le retour (ADR 0003)
suppose un mouvement physique de marchandise et s'arrête avant encaissement. Rien ne permettait de
défaire proprement une saisie fictive.

## Décision

1. **Une procédure distincte, « Annulation exceptionnelle »**, jamais un simple élargissement du
   bouton « Annuler ». Elle n'est proposée que lorsque l'annulation normale ne l'est plus.
2. **Nouveau statut terminal `annulee_erreur_saisie`**, distinct d'`annulee`. La règle « `annulee` ne
   survient jamais après le départ du véhicule » (ADR 0003) reste vraie ; les statistiques traitent
   le nouveau statut comme `annulee`. Une telle commande n'est jamais supprimable.
3. **Régularisation, jamais effacement comptable** : encaissements supprimés avec contrepassation de
   leur écriture, facture annulée avec contrepassation de la vente, sorties de stock contre-passées,
   commissions non soldées annulées, cashback en attente retiré. Pas d'avoir ni de remboursement :
   aucune vente n'a existé.
4. **Refus conservateurs** : commission déjà traitée, cashback validé ou versé, espèces déjà sorties
   de la caisse dédiée, retour déjà enregistré. Aucun engagement déjà pris n'est effacé en douce.
5. **Permission dédiée `ventes.annuler_exceptionnel`**, accordée au seul `super_admin` par défaut.
6. **Niveau de confirmation paramétrable par organisation** (révision du même jour, 24/09/2026 —
   la première version imposait le code à tous) : `ventes_annulation_exceptionnelle_confirmation`,
   dans Paramètres → Ventes.
   - `email_code`, **par défaut** : code à usage unique envoyé par e-mail à l'utilisateur
     authentifié (réutilise `OtpService` : 10 min, usage unique, 5 tentatives, anti-spam). Il prouve
     que l'utilisateur authentifié confirme ; ce n'est pas une double validation.
   - `simple` : confirmation directe, choix explicite et assumé de l'organisation.
   - Le mode est lu **côté serveur** à chaque étape, jamais fourni par le frontend.
   - Le **modifier** exige `parametres.update` (la permission réellement contrôlée par l'écran
     Paramètres → Ventes) **et** `ventes.annuler_exceptionnel`.
   - La 2FA/TOTP n'est exigée dans aucun mode.
7. **Ce qui est validé = ce qui a été affiché**, dans les deux modes : empreinte sha256 du
   récapitulatif, exigée à la confirmation (et mémorisée avec le motif à la demande de code en
   `email_code`), recalculée sous verrou avant exécution.
8. **Trace dédiée `annulations_exceptionnelles`**, sans jamais le code.
9. **Les codes OTP ne sont plus stockés en clair** dans le cache (empreinte HMAC), pour tous les
   usages d'`OtpService`.
10. **La suppression unitaire d'un encaissement exige désormais `ventes.annuler_exceptionnel`** : la
    route n'avait jusque-là aucun contrôle de permission.

## Alternatives écartées

- **TOTP (application d'authentification) obligatoire** : plus robuste et indépendant de l'e-mail,
  mais écarté par le propriétaire du produit : la 2FA ne doit pas devenir obligatoire pour cette
  fonctionnalité.
- **Double validation (demandeur → super admin)** : vraie séparation des responsabilités, mais
  disproportionnée pour des erreurs de formation ponctuelles. Peut être ajoutée plus tard sans
  remettre en cause ce modèle.
- **Réutiliser `annulee`** : aurait cassé une règle documentée (ADR 0003) et mélangé dans les
  analyses de vraies annulations avec des saisies fictives.
- **Avoir / remboursement** : pertinent pour une vente réelle annulée commercialement, pas pour une
  vente qui n'a jamais existé.
- **Statut `annule` pour le cashback** : aurait obligé à revoir tous les agrégats de la page Cashback.
  Un gain en attente n'est qu'un calcul, sans écriture comptable ; il est supprimé et ses compteurs
  restaurés, la trace restant dans `annulations_exceptionnelles`.

## Conséquences

- Migration de schéma (`annulations_exceptionnelles`) et migration de données (permission).
- Dépendance opérationnelle à l'envoi d'e-mails en mode `email_code` (défaut) : si le SMTP est
  indisponible ou refuse l'authentification, l'annulation exceptionnelle est impossible jusqu'au
  rétablissement, ou jusqu'au passage explicite de l'organisation en `simple`. Constaté en local le
  24/09/2026 (Gmail : « 534-5.7.9 Please log in with your web browser »).
- Nouveau paramètre d'organisation : sans ligne en base, le défaut `email_code` s'applique (aucune
  migration de données nécessaire).
- Les refus « dans un premier temps » (retour partiel, commission traitée, cashback validé, caisse
  versée) exigent une régularisation manuelle préalable.
