# ADR 0062 — Garde des transactions annulées par la base

- **Date** : 2026-11-09
- **Statut** : accepté (correctif d'incident)
- **Périmètre** : toutes les connexions MySQL, MariaDB et SQLite de l'application

## Contexte

Incident VTE-081126-134 (08/11/2026). Un encaissement espèces de 450 000 GNF subit un deadlock MySQL
pendant le recalcul des périodes de commission, exécuté dans la transaction de l'encaissement. Sur un
deadlock, MySQL annule **toute** la transaction, pas seulement la requête fautive. Or le recalcul est
entouré d'un `catch (Throwable)` qui journalise et continue (« un incident côté période ne doit jamais
faire échouer la génération »). Laravel croit la transaction toujours ouverte ; MySQL, lui, est
revenu en validation automatique : chaque requête suivante est validée aussitôt, seule.

Résultat : l'encaissement et le passage en « Livrée » ont disparu, mais la pièce de caisse
CA-2026-010088 et l'audit « Encaissement enregistré » sont restés. L'agente, qui a reçu une erreur,
a ressaisi : sa caisse comptait 450 000 GNF de trop. Cinq pièces orphelines de ce type ont été
trouvées en production entre le 06 et le 09/11/2026.

L'application compte 64 blocs `catch (Throwable)`, la plupart légitimes (« ne doit jamais faire
échouer l'opération »). Seul `CommissionEnveloppeGenerator` relançait les erreurs de concurrence.
Corriger chaque bloc un par un laisserait la même faille au prochain bloc ajouté.

## Décision

1. **Garde au niveau de la connexion** (`App\Support\Database\RefuseRequetesApresTransactionAnnulee`,
   branchée dans `AppServiceProvider` pour les pilotes `mysql`, `mariadb` et `sqlite`). Une requête
   en transaction qui échoue par deadlock, délai de verrou ou connexion perdue
   (`TransactionAnnulee::estCompromise`) marque la transaction comme perdue.
2. Ensuite, **toute requête** sur cette connexion est refusée (`TransactionAnnuleeException`,
   SQLSTATE 40001) jusqu'à la fin de l'opération, même si l'erreur d'origine a été masquée.
3. Si toutes les erreurs ont été masquées, la **validation finale échoue** au lieu de valider une
   transaction que la base a déjà annulée.
4. La marque disparaît au début de la transaction suivante. Une nouvelle tentative prévue par
   l'appelant (`DB::transaction($callback, $tentatives)`) repart donc sur une transaction saine.
5. Les erreurs ordinaires (contrainte, table absente, données) ne déclenchent rien. Hors transaction,
   rien n'est bloqué.
6. L'encaissement affiche un message clair (« … Rien n'a été enregistré, vous pouvez réessayer. »).
   La nouvelle saisie est sûre : le verrou de la facture relit le restant dû.

Le délai de verrou (`Lock wait timeout`) est traité comme une perte de transaction, par prudence :
selon la configuration du serveur (`innodb_rollback_on_timeout`), MySQL annule alors soit la
requête, soit la transaction entière. L'opération est refusée et l'utilisateur réessaie.

## Conséquences

- Plus aucune opération à moitié validée après un deadlock, quel que soit le `catch` traversé.
- Un deadlock devient une opération refusée (l'utilisateur réessaie) au lieu d'une donnée corrompue.
- Tests : `tests/Feature/Database/TransactionAnnuleeTest.php` (garde),
  `EncaissementVenteTest::test_deadlock_masque_pendant_l_encaissement_n_ecrit_ni_piece_ni_audit`
  (incident). Sous `RefreshDatabase`, la transaction d'enveloppe des tests est ignorée
  (`TransactionAnnulee::$niveauRacine = 1`, posé par `Tests\TestCase`).
- Vérifié sous MySQL réel avec deux processus en deadlock : sans la garde, la « pièce » écrite après
  le deadlock masqué restait en base ; avec la garde, rien ne reste.
## Régularisation des pièces déjà en base (décision de l'utilisateur, 09/11/2026)

Migration `2026_11_09_210000_contrepasser_pieces_encaissement_sans_encaissement`, au déploiement, par
`PiecesEncaissementSansEncaissementService` et le service comptable officiel (contrepassation, jamais
de suppression ni de SQL manuel). Rapport : `php artisan comptabilite:pieces-encaissement-sans-encaissement`
(simulation ; `--appliquer` pour contrepasser).

Une pièce `encaissement_vente_recu` validée dont l'encaissement n'existe plus est contrepassée
**seulement si tout est vérifié** :

1. exactement deux écritures équilibrées : trésorerie (classe 5) au débit, client 411 au crédit ;
2. aucune pièce « pour le compte d'une autre agence » liée ;
3. la facture du libellé existe dans la même organisation ;
4. soit la facture est annulée et n'a aucun encaissement, soit elle est soldée par ses encaissements
   réels et chacun a sa propre pièce validée (la pièce perdue est alors un doublon).

Sinon la pièce est « à examiner » et n'est jamais touchée. Exemple : une facture non soldée, car
l'argent a peut-être été reçu. Chaque contrepassation porte le motif « Incident 08/11 : encaissement
annulé par la base de données… » et l'audit de la pièce. Elle se fait dans une transaction qui
revérifie tout sous verrou. La relance est sans effet, car une pièce contrepassée n'est plus validée.
Le bilan est écrit dans le journal de l'application.

Constat de production du 09/11/2026, avant déploiement : 5 pièces (CA-2026-009540, 009548, 009652,
010088, 010338), 3 caisses gonflées (Mamadouba KEITA, Sonfonia : 1 408 000 ; Fatoumata SIDIBE, Cba :
1 200 000 ; Dalanda CONDÉ, Matoto : 900 000), soit 3 508 000 GNF. Le contrôle des encaissements ne les
avait pas signalées : aucun contrôle n'a jamais tourné en production (`controles_encaissements` vide).
