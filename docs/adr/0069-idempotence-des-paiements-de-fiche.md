# ADR 0069 — Idempotence des paiements de fiche

- **Date** : 2026-11-20
- **Statut** : accepté le 2026-11-20 (demande de l'utilisateur)
- **Complète** : [ADR 0009](0009-paiement-de-fiche-decaissement-tresorerie.md) (paiement de fiche =
  décaissement réel) et [ADR 0068](0068-paiement-groupe-des-fiches-de-commission.md) (paiement groupé)
- **Détail fonctionnel** : [docs/commissions.md](../commissions.md), § Idempotence des paiements

## Contexte

Un paiement de fiche (bouton Payer ou paiement groupé) déplace de l'argent réel : une ligne de
paiement, une écriture comptable, une sortie du compte de trésorerie, un historique et une
notification. Avant cette décision, seul l'état de la fiche protégeait d'un doublon :

- le paiement groupé paie toujours le reste entier, donc un second envoi trouvait un reste nul ;
- le bouton Payer, lui, acceptait deux envois du même paiement **partiel** (double clic, nouvelle
  tentative après une coupure) : deux paiements de 50 000 GNF pour une seule intention ;
- un renvoi ne restituait jamais le résultat initial ;
- les tests tournant sous SQLite, où `lockForUpdate()` est sans effet, les verrous n'avaient jamais
  été éprouvés.

## Décision

1. **Une clé d'opération par paiement voulu**, générée par l'écran et envoyée dans l'en-tête
   `Idempotency-Key`. Elle est obligatoire sur les deux points d'entrée : `POST
   /comptabilite/fiches/{fiche}/paiements` et `POST /comptabilite/fiches/paiements/lot`. Sans clé
   valide, le paiement est refusé.
2. **La clé est posée sur la ligne de paiement elle-même**, pas dans une table à part. Colonnes
   `operation_id` et `operation_empreinte` de `paiement_fiche_paiements`, contrainte unique
   `(fiche_id, operation_id)` : la base interdit deux paiements de la même opération sur une fiche.
   Migration non destructive, colonnes vides pour l'historique.
3. **Empreinte des paramètres** : `operation_empreinte` est le SHA-256 de tout ce qui définit le
   paiement. Pour le bouton Payer : fiche, montant, mode, compte, référence, date, note. Pour un lot :
   les fiches et leurs montants, les références, le mode, le compte, la date, la note. **Une clé
   rejouée avec d'autres paramètres est refusée**, sans rien enregistrer.
4. **Rejeu = résultat initial.** Une clé déjà enregistrée avec la même empreinte restitue le
   paiement ou le lot d'origine, avant tout contrôle qui dépend de l'état actuel de la fiche (reste
   à payer, période). Rien n'est écrit, et ni notification ni historique ne repartent.
5. **Le contrôle a lieu sous verrou**, dans `PaiementFicheService` (mécanisme commun aux deux
   points d'entrée et à tous les types de fiche : livreurs, propriétaires, sites, consultants) :
   - la fiche est verrouillée, puis l'opération relue : un envoi jumeau en attente trouve le
     paiement du premier et le restitue ;
   - la violation de la contrainte unique sert de dernier filet et se traite aussi en rejeu.
6. **L'écran garde la même clé tant que les paramètres ne changent pas** : double clic et nouvelle
   tentative réutilisent la clé, une correction en génère une nouvelle, et chaque ouverture de la
   fenêtre commence une nouvelle opération (`resources/js/lib/cleOperation.ts`). Pour le paiement
   groupé, chaque lot a sa clé : `<opération>.<numéro du lot>`.
7. **Deux opérations différentes restent deux paiements**, comme deux paiements partiels voulus.
   Les verrous les départagent : le reste à payer et le solde du compte sont relus sous verrou, et
   le second envoi est refusé s'il dépasse le reste ou le solde.
8. **Preuve sous MySQL réel** : `tests/Feature/Comptabilite/PaiementFicheConcurrenceTest.php`. Il
   lance des requêtes simultanées dans des processus distincts sur la base dédiée
   `elm_monolithe_concurrency_test`. Il est ignoré en CI faute de base.
9. **Solde relu par une lecture verrouillante** (`TresorerieDisponibiliteService::garantirSoldeSuffisant`).
   Le test MySQL a montré que deux lots simultanés sur un compte qui n'en couvrait qu'un passaient
   tous les deux : le compte devenait négatif. Le second attendait bien le verrou du compte, mais
   relisait le solde sur l'instantané REPEATABLE READ de sa transaction, figé par une lecture
   antérieure (la recherche de la clé d'opération). Le solde est désormais relu avec jointure et
   FOR UPDATE, règle déjà appliquée par `FactureFournisseurService` et l'encaissement des ventes.
   Cela vaut pour toutes les sorties d'argent qui passent par cette garde, pas seulement les
   paiements de fiche.

## Options écartées

- **Table d'opérations séparée** (clé, réponse enregistrée) : une seconde source de vérité à
  garder cohérente avec les paiements, alors que la ligne de paiement suffit à reconstruire le
  résultat.
- **Clé facultative** : un écran ou un client oublié resterait exposé au doublon partiel.
- **Clé déduite des paramètres par le serveur** (même montant, même jour) : elle bloquerait deux
  paiements légitimes identiques.

## Conséquences

- Les tests qui paient une fiche envoient une clé, comme l'écran.
- `useDecaissement` envoie aussi la clé pour les salaires, mais le serveur des salaires ne l'utilise
  pas encore : la Paie a sa propre chaîne de paiement, hors de cette décision.
- **Limite connue** : supprimer un paiement efface aussi sa clé. Renvoyer ensuite exactement la
  même requête le recréerait. Aucun écran ne le fait : la fenêtre se ferme après le succès, et la
  clé change à la réouverture.
