# Trésorerie inter-agences — encaisser la commande d'une autre agence

Décision : [ADR 0012](adr/0012-encaissement-inter-agences-et-reglement.md). État : **lot 1 livré**
(29/09/2026) — encaissement, comptabilité, dette, règlement côté service. Lot 2 : écran
Trésorerie → Inter-agences et règlement dans l'interface. Lot 3 : rapports, Situation, financement.

## Exemple

Commande de **Matoto (A)**, 500 000 GNF. Le client paie à **Kindia (B)**.

| | Agence |
|---|---|
| Commande, facture, chiffre d'affaires, créance client, commissions, cashback, stock | A (inchangé) |
| Agence qui encaisse, moyens proposés, caisse dédiée, trésorerie qui reçoit l'argent | B |
| Dette | B doit 500 000 à A |

Puis B reverse l'argent à A par un **règlement inter-agences** : la dette est soldée.

| Encaissement | Agence commande | Agence encaissement | Dette |
|---|---|---|---|
| Commande A payée chez A | A | A | Non |
| Commande A payée chez B | A | B | **B doit à A** |
| Commande B payée chez A | B | A | **A doit à B** |

Pas de compensation : si B doit 500 000 à A et A doit 300 000 à B, les deux dettes restent séparées.

## Encaisser (lot 1)

- Écran **Factures** → bouton **« Encaisser une commande d'une autre agence »** (visible avec
  `factures.encaisser` + `factures.encaisser_autre_agence`). Recherche par **référence exacte** de la
  commande ou de la facture (`GET /backoffice/factures/autre-agence?reference=`,
  `RechercherFactureAutreAgenceController`) : jamais de liste des ventes des autres agences.
- La fenêtre de paiement (`PaymentCard`, prop `agences`) propose les **agences de l'utilisateur** :
  l'agence de la commande est présélectionnée s'il y est affecté ; une liste n'apparaît que s'il a
  plusieurs agences possibles. Les moyens (supports actifs) et les espèces (caisse dédiée active)
  sont ceux de l'agence choisie. Bandeau bleu (information) quand l'argent est reçu ailleurs :
  « Commande de A encaissée à B : B devra reverser ce montant à A ».
- Les écrans existants (fiche vente, listes Ventes et Factures) n'envoient pas d'agence : l'argent
  reste reçu par l'agence de la facture, comme avant.

### Règles serveur — `AgenceEncaissementResolver` (source unique écran + contrôle)

| Demande | Résultat |
|---|---|
| Aucune agence, ou l'agence de la facture | Agence de la facture (comportement historique, quelle que soit l'affectation) |
| Une autre agence, sans `factures.encaisser_autre_agence` | Refus sur `site_encaissement_id` |
| Une autre agence à laquelle l'utilisateur n'est pas affecté (y compris admin, autre organisation) | Refus sur `site_encaissement_id` |
| Une autre agence de l'utilisateur, avec la permission | Acceptée |

Ensuite, `StoreEncaissementVenteController` applique les règles habituelles **à l'agence
d'encaissement** : support actif de cette agence (`MoyensEncaissementResolver`), espèces
uniquement avec une caisse dédiée active de l'auteur sur cette agence (`CaisseAgentResolver`).

## Comptabilité

Compte **181000 « Comptes de liaison des agences »**, tiers = agence contrepartie (`compta_tiers`,
type `agence`). Deux pièces mono-site par encaissement dans une autre agence :

| Pièce | Site | Débit | Crédit |
|---|---|---|---|
| `encaissement_vente_recu` | B (encaissement) | Trésorerie B (support ou caisse dédiée) | 181000 [tiers A] |
| `encaissement_vente_pour_compte` | A (commande) | 181000 [tiers B] | 411000 client |

Un encaissement dans l'agence de la commande garde sa pièce unique (débit trésorerie / crédit
client). Supprimer un encaissement non réglé contrepasse ses deux pièces.

Règlement inter-agences (mouvement de fonds, nature `reglement_agences`) :

| Pièce | Site | Débit | Crédit |
|---|---|---|---|
| `mouvement_fonds_envoye` | B | 181000 [tiers A] | Trésorerie B |
| `mouvement_fonds_recu` | A | Trésorerie A | 181000 [tiers B] |

Les mouvements ordinaires gardent le transit 588000. Au niveau de l'organisation, le 181000 vaut 0
hors règlements en route.

## Dette et règlement

`DetteInterAgencesService` dérive la dette des encaissements eux-mêmes (aucune table de dettes, aucun
montant saisi). Statut de chaque encaissement :

| Statut | Signification | Compte dans « À verser » (B) | Compte dans « À recevoir » (A) |
|---|---|---|---|
| À verser | Aucun règlement | Oui | Oui |
| Réservé | Dans un règlement en brouillon | Oui | Oui |
| En cours de versement | Règlement envoyé ou contesté | Non | Oui |
| Versé | Règlement reçu par A | Non | Non |

`ReglementInterAgencesService::creerBrouillon()` crée le règlement depuis une sélection
d'encaissements : même sens pour tous (B → A), tous de l'organisation, aucun déjà engagé, montant =
leur somme. Ensuite, workflow habituel de `MouvementFondsService` (envoi sous contrôle de solde,
réception par A avec choix du support, contestation, retour). Annulation du brouillon ou retour
confirmé : les encaissements redeviennent « à verser ». Table `mouvement_fonds_encaissements` :
`encaissement_actif_id` unique = un encaissement dans un seul règlement actif, garanti en base.

## Annulation

- Encaissement **non engagé** dans un règlement : suppression (permission
  `ventes.annuler_exceptionnel`) ou annulation exceptionnelle possibles ; les deux pièces sont
  contrepassées, la dette disparaît.
- Encaissement **engagé** dans un règlement actif, même en brouillon : suppression et annulation
  exceptionnelle **refusées** (garde du modèle `EncaissementVente::deleting`, message dans
  `AnnulationExceptionnelleService`). Pour un brouillon : annuler d'abord le règlement. Après
  versement : relèvera d'un futur mécanisme de remboursement/régularisation.

## Hors lot 1

- Écran Inter-agences (À verser / À recevoir, détail, règlement) — lot 2.
- Rapports par agence d'encaissement, Situation, financement (disponible de B diminué de ce qu'il
  doit : `DetteInterAgencesService::aVerserParSite()`) — lot 3.
- Les sorties d'argent de B ne sont pas bloquées par la dette (décision du 29/09/2026).
