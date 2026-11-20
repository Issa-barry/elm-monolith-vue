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
9. **Solde relu hors de la transaction, après le verrou du support**
   (`TresorerieDisponibiliteService::garantirSoldeSuffisant`). Deux défauts trouvés par les tests
   MySQL, l'un après l'autre :
   - **solde périmé** : deux lots simultanés sur un compte qui n'en couvrait qu'un passaient tous
     les deux, et le compte devenait négatif. Le second attendait bien le verrou du support, mais
     relisait le solde sur l'instantané REPEATABLE READ de sa transaction, figé par une lecture
     antérieure (la recherche de la clé d'opération) ;
   - **interblocage** : la première correction relisait le solde par une lecture verrouillante
     (jointure et FOR UPDATE). Elle pose des verrous d'intervalle sur les index du grand livre :
     deux agences qui payaient en même temps depuis le même compte comptable (Orange Money 561100,
     caisses 571000) s'interbloquaient, et MySQL refusait l'un des deux paiements.

   Le solde est désormais lu par une lecture simple sur une connexion distincte, hors de la
   transaction (`App\Support\Database\ConnexionHorsTransaction`, lecture seulement : une écriture
   par cette connexion attendrait les verrous de la transaction elle-même) : elle voit tout ce qui
   est validé, sans aucun verrou sur le grand livre. Le verrou
   du support, pris juste avant, garantit qu'aucune autre sortie de ce support n'est en cours :
   toutes les précédentes sont donc comptées. La lecture ne voit pas les sorties encore non validées
   de sa propre transaction : chacun des sept appelants contrôle le total avant d'écrire sa sortie
   (le paiement groupé contrôle la somme du lot). Sous SQLite, lecture directe. Preuve :
   `tests/Feature/Tresorerie/SoldeSupportConcurrenceTest.php`, garde commune aux paiements de
   fiche, au paiement groupé, aux paiements fournisseur, aux salaires, aux mouvements de fonds et
   aux remboursements de vente.
10. **Suppression d'un paiement tout ou rien** (`PaiementFicheService::supprimer`). Avant, la
    suppression ne tournait dans aucune transaction et ne verrouillait rien :
    - un échec de contrepassation laissait l'historique « Paiement annulé » et la désallocation des
      parts écrits ;
    - deux suppressions simultanées écrivaient deux historiques.

    Désormais, tout tient dans une transaction, sous le verrou de la fiche. Le paiement est relu
    par une lecture verrouillante (une ligne par clé primaire, sans verrou d'intervalle) : un
    second envoi le trouve supprimé et n'écrit rien.
11. **Un paiement annulé garde son identité.** Supprimer un paiement le copie d'abord dans
    `paiement_fiche_paiements_annules`, avec le même identifiant (donc le même `source_id` que sa
    pièce contrepassée), sa clé, son empreinte, tout son détail, l'auteur et la date d'annulation.
    L'annulation financière (contrepassation) est distincte de l'identité de l'opération, qui
    reste. Une clé archivée ne recrée jamais de paiement : tout renvoi est refusé, quels que soient
    ses paramètres, et un lot dont l'un des paiements a été annulé n'est pas rejoué. Le contrôle a
    lieu dans `paiementsDeLOperation`, sous le verrou de la fiche, comme le rejeu.
12. **Numérotation des pièces sans interblocage** (`PieceNumerotationService`), défaut antérieur
    trouvé par la même vérification : deux pièces simultanées du même journal s'interbloquaient
    sur leur compteur, et l'une des deux sorties d'argent échouait. Le compteur est désormais
    verrouillé avant toute insertion (détail : [dictionnaire compta](../data-dictionary-compta.md),
    `compta_piece_sequences`). Cela vaut pour toutes les pièces comptables.

## Options écartées

- **Table d'opérations séparée pour tous les paiements** (clé, réponse enregistrée) : une seconde
  source de vérité à garder cohérente avec les paiements, alors que la ligne de paiement suffit à
  reconstruire le résultat. Seuls les paiements annulés sont archivés à part (décision 11).
- **Suppression logique des paiements** (`deleted_at`) : chaque somme et chaque jointure qui lit
  `paiement_fiche_paiements` devrait exclure les lignes supprimées ; un oubli fausserait le reste à
  payer.
- **Clé facultative** : un écran ou un client oublié resterait exposé au doublon partiel.
- **Clé déduite des paramètres par le serveur** (même montant, même jour) : elle bloquerait deux
  paiements légitimes identiques.

## Conséquences

- Les tests qui paient une fiche envoient une clé, comme l'écran.
- `useDecaissement` envoie aussi la clé pour les salaires, mais le serveur des salaires ne l'utilise
  pas encore : la Paie a sa propre chaîne de paiement, hors de cette décision.
- Une sortie d'argent ouvre une seconde connexion à la base, pour la seule lecture du solde.
- Deux supports d'une même agence qui partagent leur compte comptable partagent aussi leur solde,
  sans verrou commun : limite antérieure, déjà notée sur `soldePourSupport()`.

## Connexion hors transaction

`App\Support\Database\ConnexionHorsTransaction` (décision 9) ouvre une seconde connexion à la même
base pour lire le solde validé. Ses règles, à respecter par toute évolution :

- **Lecture seulement.** Une écriture par cette connexion attendrait les verrous que la transaction
  appelante tient déjà (vérifiée : 50 s d'attente puis échec, sur la numérotation des pièces).
- **Serveur principal, toujours.** La copie de configuration retire `read`, `write` et `sticky`. Si
  des réplicas de lecture sont ajoutés un jour, le solde reste lu sur le serveur principal : un
  réplica en retard rendrait un solde périmé sans aucune erreur visible. Preuve :
  `SoldeSupportConcurrenceTest`, réplica injoignable configuré, lecture réussie.
- **Changement de connexion.** La copie est dérivée de la connexion du support, qui doit être celle
  de la transaction appelante, où le verrou du support est pris. Un appelant qui ouvrirait sa
  transaction sur une autre connexion que le modèle ne serait plus protégé.
- **Plusieurs instances de l'application** (plusieurs serveurs, files de travail) : sans effet sur
  la garantie, qui repose sur le verrou du support en base, jamais sur la mémoire d'un processus.
- **Nombre de connexions.** Une connexion de plus par requête qui fait une sortie d'argent, le
  temps de la requête. À prendre en compte dans la limite de connexions de l'hébergement.
- **Sous SQLite** (tests) : la connexion d'origine est rendue telle quelle.

## Suites à donner (décisions du 20/11/2026)

- **Compteurs de numérotation à l'ouverture de l'exercice** : ADR dédiée, chantier séparé. À
  garantir : unicité des numéros, aucun doublon entre deux traitements simultanés, tous les
  journaux, reprise après un échec d'ouverture. D'ici là, deux premières pièces simultanées d'un
  même journal dans un exercice peuvent s'interbloquer (une fois par journal et par an) : accepté
  temporairement ([dictionnaire compta](../data-dictionary-compta.md), `compta_piece_sequences`).
- **Dépenses** : aucune garde de solde à ce jour ; à traiter dans un chantier de sécurisation de la
  trésorerie.
- **Tests MySQL en CI** : les tests `*ConcurrenceTest.php` sont ignorés en CI faute de base ; à
  prévoir dans une évolution de la CI.
- **Mise en production** : après la recette Formation (paiements individuels, partiels, groupés,
  cinq modes, annulations, soldes). Le déploiement exécute `php artisan migrate` (table
  `paiement_fiche_paiements_annules`).
