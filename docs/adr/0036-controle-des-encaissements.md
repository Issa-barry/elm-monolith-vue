# ADR 0036 — Contrôle des encaissements : détecter, alerter, régulariser sous contrôle humain

- **Date** : 2026-10-24
- **Statut** : accepté le 2026-10-24 (décisions de l'utilisateur pendant le chantier)
- **Détail fonctionnel** : [docs/controle-encaissements.md](../controle-encaissements.md)
- **Lié** : correctif anti-double encaissement (verrou de facture, [docs/encaissements.md](../encaissements.md)),
  [ADR 0014](0014-unicite-des-references-mobile-money.md) (références Mobile Money),
  [ADR 0019](0019-precommande-vente-avec-acompte-et-reservation.md) (trop-perçu et remboursement),
  [ADR 0011](0011-permissions-des-roles-systeme-non-ecrasees-au-deploiement.md) (backfill de permissions)

## Contexte

Le 22/10/2026, la commande VTE-221026-080 (450 000 GNF) a été encaissée deux fois en espèces à la
même seconde : 900 000 GNF enregistrés. L'analyse de production a trouvé deux autres cas
(VTE-151026-146, VTE-221026-145). Un correctif empêche désormais la course (verrou de la facture,
contrôle du restant dû dans la transaction), mais :

- les anomalies déjà présentes n'étaient visibles qu'avec du SQL ;
- d'autres incohérences (écriture comptable sans encaissement, référence Mobile Money réutilisée)
  peuvent survenir hors du formulaire protégé ;
- personne n'était prévenu.

## Décision

1. **Anomalies persistantes** (`anomalies_encaissement`) à identité stable `(organisation, clé)` :
   un même problème n'est jamais enregistré ni notifié deux fois. Statuts : Nouvelle, En cours
   d'analyse, Résolue, Fausse alerte. Historique dans `audit_logs`.
2. **Deux détections** : contrôle immédiat de la facture après chaque encaissement, suppression ou
   remboursement (hooks de modèle, après validation de la transaction, jamais bloquant), et
   contrôle complet planifié toutes les heures (`encaissements:surveiller`), qui rattrape
   l'historique, constate les écarts disparus et relance.
3. **Destinataires par permission, jamais par nom de rôle** : l'agent ou les agents qui ont
   encaissé, plus les utilisateurs ayant `encaissements.surveiller` (super administrateur compris)
   et voyant l'agence. « Administrateur supérieur » et « Assistante de direction » reçoivent la
   permission dans Rôles. Traitement : `encaissements.traiter_anomalies`.
4. **Alertes toujours envoyées dans l'application**, quelles que soient les préférences de
   notification (contrôle financier). E-mail pour les gravités critique et attention, un seul
   message par destinataire et par envoi (pas de rafale au premier contrôle de l'historique).
5. **Suivi de livraison** de chaque alerte (`anomalie_encaissement_notifications`) : un e-mail en
   échec (SMTP, quota Gmail) reste « échec » et est retenté au contrôle suivant, cinq fois au plus.
   Les e-mails partent après la réponse HTTP : un encaissement n'attend jamais le SMTP.
6. **Relance** des anomalies restées « Nouvelle » toutes les 24 h, trois fois au plus, aux
   responsables seulement.
7. **Aucune correction automatique.** La régularisation est humaine et passe par les procédures
   existantes : annulation d'un encaissement en doublon par la route `encaissements.destroy`
   (`ventes.annuler_exceptionnel`, contrepassation, audit), proposée seulement après prise en
   charge de l'anomalie ; remboursement d'une précommande par son circuit (`ventes.rembourser`).
   « Résolue » est refusée tant qu'un contrôle refait à l'instant constate encore l'écart ;
   « Fausse alerte » exige un motif.
8. **Écran** : Comptabilité → Trésorerie → Contrôle des encaissements. Une personne qui a
   `encaissements.surveiller` sans `comptabilite.read` ne voit que cette entrée du menu.

## Conséquences

- Point ouvert signalé : **aucun circuit de remboursement hors précommande**. Un surplus
  réellement payé par le client sur une vente ordinaire ne peut pas être rendu dans l'application ;
  la fiche l'indique et oriente vers la comptabilité. Toute extension du remboursement est une
  décision métier à prendre séparément.
- Le contrôle planifié dépend du cron `schedule:run` du serveur ; l'écran signale un contrôle
  planifié absent depuis plus de 2 h 30, et le bouton « Analyser maintenant » permet un contrôle à
  la demande.
- Les e-mails dépendent du SMTP de production ; la notification dans l'application, elle, est
  toujours enregistrée.
