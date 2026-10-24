# Contrôle des encaissements

Comptabilité → Trésorerie → **Contrôle des encaissements** (`/backoffice/comptabilite/tresorerie/controle-encaissements`).
Décision : [ADR 0036](adr/0036-controle-des-encaissements.md).

Le contrôle détecte automatiquement les doubles encaissements, les surplus et les incohérences
comptables, prévient les personnes concernées et guide une régularisation **humaine**. Il ne
corrige jamais une donnée financière : aucune suppression, aucun remboursement, aucune
contrepassation automatique.

Il complète la protection du formulaire d'encaissement (verrou de la facture et contrôle du
restant dû dans la transaction, cf. [encaissements.md](encaissements.md)) : la protection empêche
la course, le contrôle repère ce qui serait passé malgré tout (données historiques, saisies hors
formulaire, SQL direct).

## Règles de détection

Encaissé net = somme des encaissements − remboursements (`factures_ventes.montant_rembourse`). Un
encaissement supprimé n'existe plus et sa pièce est contrepassée : il n'est jamais compté.

| Type | Règle | Gravité | Certitude |
|---|---|---|---|
| Double encaissement probable | encaissé net > facturé, et deux encaissements de même montant, même mode et même agent à ≤ 120 s d'intervalle expliquent le surplus | Critique | Certaine (écart) |
| Surplus encaissé | encaissé net > facturé, sans doublon évident, hors précommande | Critique | Certaine |
| Doublon suspect | deux encaissements identiques (montant, mode, agent) à ≤ 120 s, facture **non** dépassée | Attention | Suspicion |
| Trop-perçu à rembourser | précommande dont l'encaissé net dépasse le facturé (ou précommande annulée non remboursée) | Attention | Certaine |
| Incohérence comptable | encaissement sans pièce `encaissement_vente_recu` validée, ou pièce validée dont l'encaissement n'existe plus | Critique | Certaine |
| Référence Mobile Money réutilisée | même référence normalisée (majuscules, sans espaces de bord) sur plusieurs encaissements | Information | Suspicion |

Ne sont **jamais** des doublons : deux paiements de montants différents ; deux paiements de même
montant par deux agents différents ; deux paiements de même montant espacés de plus de 120 s.

Une facture a au plus une anomalie d'écart (clé `ecart:{facture}`) : son type suit la situation
(surplus → double encaissement si un doublon apparaît). Clés : `doublon:{facture}:{enc1}:{enc2}`,
`compta:encaissement:{id}`, `compta:piece:{id}`, `reference_mobile_money:{REF}`.

## Quand le contrôle tourne

- **Immédiatement** après chaque encaissement créé ou supprimé et chaque remboursement (hooks de
  `EncaissementVente` et `RemboursementVente`), quel que soit l'écran (Ventes, Factures,
  Distribution, PDV, encaissement pour une autre agence, précommande). Il s'exécute après la
  validation de la transaction et ne bloque jamais l'opération : une erreur est seulement
  journalisée.
- **Toutes les heures** : `php artisan encaissements:surveiller --source=planificateur`
  (`routes/console.php`). Contrôle complet de toutes les organisations : nouvelles anomalies,
  écarts disparus, relances, e-mails en attente ou en échec.
- **À la demande** : bouton « Analyser maintenant » (`encaissements.traiter_anomalies`).

Chaque contrôle complet est journalisé (`controles_encaissements`). L'écran affiche le dernier
contrôle planifié et signale une alerte si aucun n'a tourné depuis 2 h 30 (cron absent).

## Cycle de vie d'une anomalie

- **Nouvelle** à la détection.
- **Écart disparu** : le contrôle ne constate plus le problème (doublon annulé, remboursement
  fait…). L'anomalie reste ouverte, avec la cause enregistrée (ex. « Suppression de l'encaissement
  de 450 000 GNF par … »).
- **En cours d'analyse** : prise en charge par une personne habilitée (analyse facultative).
- **Résolue** : seulement si un contrôle refait au moment du clic ne constate plus l'écart.
- **Fausse alerte** : motif obligatoire ; plus jamais notifiée.
- Une anomalie **résolue** dont l'écart réapparaît est rouverte (« Nouvelle », détection suivante)
  et réalertée ; une fausse alerte reste close.

Toutes les actions sont historisées (`audit_logs`) : détection, écart disparu ou réapparu,
changements de statut avec auteur, date et commentaire, commentaires libres.

## Régularisation

La fiche propose la démarche selon le type, jamais un bouton « Corriger » générique.

- **Doublon confirmé** (double encaissement, surplus, doublon suspect) : après prise en charge,
  chaque encaissement concerné porte un bouton « Annuler cet encaissement »
  (`ventes.annuler_exceptionnel`). Une confirmation récapitule l'encaissement, l'effet sur la
  facture (encaissé et statut après), le compte de trésorerie qui diminue et les pièces
  contrepassées, et exige de cocher « J'ai vérifié avec l'agent… ». L'annulation passe par la
  procédure existante `encaissements.destroy` : contrepassation, recalcul, audit, refus si
  l'encaissement est engagé dans un règlement inter-agences.
- **Trop-perçu de précommande** : lien vers la commande, onglet Facturation, pour rembourser
  (`ventes.rembourser`). L'écart disparaît dès le remboursement enregistré.
- **Surplus réellement payé hors précommande** : l'application n'a pas de circuit de
  remboursement ; la fiche le signale. Ne jamais supprimer un encaissement légitime pour faire
  disparaître l'écart : traiter avec la comptabilité, puis classer avec un commentaire.
- **Incohérence comptable** : vérification par la comptabilité avant toute autre opération.

## Alertes

Destinataires (aucun nom de rôle codé) :

1. l'agent ou les agents qui ont encaissé ;
2. les utilisateurs actifs de l'organisation qui ont `encaissements.surveiller` — rôle super
   administrateur compris — et qui voient l'agence de l'anomalie (rattachement ou vision toutes
   agences). « Administrateur supérieur », « Assistante de direction » : cocher la permission
   sur leur rôle dans Rôles.

Canaux :

- **Application** (cloche + badge du menu) : toujours, quelles que soient les préférences de
  notification. Ouvrir la fiche marque l'alerte comme lue.
- **E-mail** : gravités critique et attention. Un seul e-mail par destinataire et par envoi, qui
  regroupe ses alertes ; envoyé après la réponse HTTP (contrôle immédiat) ou en fin de contrôle.

Contenu : commande et facture, agence, agent, montants facturé / encaissé net / surplus, détail
des encaissements (montant, date et heure, agent, mode, référence), type, gravité, démarche et
lien vers la fiche.

Suivi : chaque envoi est une ligne `anomalie_encaissement_notifications` (destinataire, canal,
vague, état, tentatives, dernière erreur) visible dans la fiche. Un e-mail en échec est retenté
au contrôle suivant, cinq tentatives au plus. Relance des anomalies restées « Nouvelle » (critique
et attention) toutes les 24 h, trois fois au plus, aux responsables seulement.

## Permissions

| Permission | Effet |
|---|---|
| `encaissements.surveiller` | voir l'écran et les fiches (agences de l'utilisateur), recevoir les alertes |
| `encaissements.traiter_anomalies` | prendre en charge, commenter, classer résolue ou fausse alerte, analyser maintenant |
| `ventes.annuler_exceptionnel` (existante) | annuler un encaissement en doublon |
| `ventes.rembourser` (existante) | rembourser un trop-perçu de précommande |

Migration `2026_10_24_100100_backfill_permissions_controle_encaissements` : les deux nouvelles
permissions sont données à `admin_entreprise` (super administrateur : d'office). Les autres rôles
les reçoivent dans Rôles. Sans `comptabilite.read`, le menu Comptabilité ne montre que cette page.

## Déploiement

1. `php artisan migrate` (trois tables + permissions).
2. Cron du serveur : `* * * * * php artisan schedule:run` (sans lui, ni contrôle horaire ni
   relance ; l'écran le signale).
3. Cocher `encaissements.surveiller` (et `encaissements.traiter_anomalies` pour qui traite) sur les
   rôles concernés.
4. Premier contrôle : automatique à l'heure suivante, ou bouton « Analyser maintenant ».
5. SMTP de production opérationnel : sinon les e-mails restent en échec et sont retentés ; les
   alertes dans l'application, elles, sont enregistrées.
