# Trésorerie inter-agences — encaisser la commande d'une autre agence

Décision : [ADR 0012](adr/0012-encaissement-inter-agences-et-reglement.md). État : **lot 1 livré**
(29/09/2026) — encaissement, comptabilité, dette, règlement côté service ; **lot 2 développé**
(29/09/2026) — écran Trésorerie → Inter-agences, règlement dans l'interface, Mouvements, fiche
commande, compteur. Lot 3 : rapports, Situation, financement, E2E.

> **Évolution proposée — [ADR 0016](adr/0016-remise-au-siege-et-position-des-agences.md)** :
> les fonds encaissés pour une autre agence sont remis au site central de trésorerie, et cette remise
> solde la dette inter-agences (plus de règlement direct vers une agence non centrale). Statut :
> proposé.
>
> **Déjà en vigueur (02/10/2026, ADR 0016 lot 0.3)** : la dette suit l'agence qui **détient**
> l'argent (`encaissements_ventes.site_detenteur_id`, site du compte qui l'a reçu), pas l'agence qui
> encaisse. Payé sur un compte commun détenu par l'agence de la commande : aucune dette, même encaissé
> ailleurs. Payé sur un compte commun détenu par S pour une commande de A : S doit à A. Partout
> ci-dessous, « l'agence qui a encaissé » (B) se lit « l'agence qui détient l'argent » ; l'agence qui
> a encaissé reste affichée (détail Inter-agences, fiche commande « Compte commun de S »).

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
- Les écrans existants (fiche vente et distribution, listes Ventes et Factures) proposent eux aussi
  les agences de l'utilisateur (`encaissement_agences`, `AgenceEncaissementResolver::pourEcran()`) :
  le bouton « Encaisser » ordinaire encaisse dans l'agence de l'utilisateur, avec ses moyens et sa
  caisse. S'il ne peut encaisser nulle part, la fenêtre l'explique (rouge) et Confirmer est bloqué.

### Règles serveur — `AgenceEncaissementResolver` (source unique écran + contrôle)

**Agence d'encaissement = agence de l'utilisateur qui encaisse** — jamais l'agence de la facture
par défaut (règle précisée le 29/09/2026).

| Situation | Résultat |
|---|---|
| Utilisateur affecté à aucune agence (administrateur, super admin compris) | Refus : « Vous n'êtes affecté à aucune agence. Vous ne pouvez pas effectuer cet encaissement. » |
| Affecté à l'agence de la commande, aucune agence demandée | Agence de la commande |
| Non affecté à l'agence de la commande, sans `factures.encaisser_autre_agence` | Refus |
| Non affecté à l'agence de la commande, avec la permission, aucune agence demandée | Son agence par défaut (ou sa seule agence) |
| Agence demandée à laquelle l'utilisateur n'est pas affecté (autre organisation comprise) | Refus |
| Autre agence de l'utilisateur demandée, avec la permission | Acceptée |

Scénario de référence : commande créée à **Matoto**, agent affecté à **CBA** → encaissée à CBA,
espèces dans la caisse de l'agent à CBA (jamais sa caisse de Matoto ; sans caisse active à CBA,
refus), pièce comptable à CBA, CBA doit reverser à Matoto, historique « Encaissé à CBA », rapport
« Créée à Matoto / Encaissée à CBA ».

Ensuite, `StoreEncaissementVenteController` applique les règles habituelles **à l'agence
d'encaissement** : support actif de cette agence (`MoyensEncaissementResolver`), espèces
uniquement avec une caisse dédiée active de l'auteur sur cette agence (`CaisseAgentResolver`).

## Deux circuits distincts : l'agent, puis l'agence

```
Commande créée à Matoto
  → encaissée par un agent affecté à CBA          (caisse dédiée de l'agent à CBA)
  → Versement de caisse : caisse agent → caisse de l'agence CBA   (même agence, compte 588)
  → dette inter-agences : CBA doit à Matoto        (compte 181, calculée depuis l'encaissement)
  → Règlement inter-agences : CBA → Matoto         (seul mouvement qui solde la dette)
```

- **L'agent** remet toujours ses espèces à la caisse de **son** agence (versement de caisse), même
  pour une commande d'une autre agence. « Ma situation » lui montre seulement « Ma caisse · À
  remettre » et « À remettre à la caisse de {agence} » — jamais « à reverser à Matoto ».
- **L'agence** porte la dette : Trésorerie → Inter-agences (« À verser » / « À recevoir ») et le
  rapport d'activité des responsables (« pour {agence} · à reverser »).
- Un **versement de caisse** et un mouvement **« Transfert entre agences »** ordinaire ne soldent jamais une
  dette inter-agences : seul le **règlement inter-agences** le fait.

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

## Écran Trésorerie → Inter-agences (lot 2)

`InterAgencesController` — interface seulement : dette par `DetteInterAgencesService`, règlement par
`ReglementInterAgencesService` puis `MouvementFondsService::envoyer()`.

| Écran | Contenu | Accès |
|---|---|---|
| Inter-agences (`/backoffice/comptabilite/tresorerie/inter-agences`) | Une carte par agence : « À verser à d'autres agences » et « À recevoir d'autres agences », montant par contrepartie, totaux. Filtre Agence (`site_ids[]`) | `tresorerie.read` ; admin : toute l'organisation, sinon ses agences (le filtre ne l'élargit jamais) |
| Détail `…/inter-agences/{B}/{A}` | Encaissements reçus par B pour des commandes de A : commande, client, date, moyen, auteur, montant, statut (`StatusDot` : À verser, Réservé, En cours de versement, Versé) et référence du règlement. Filtre Statut | `tresorerie.read` + accès à B ou à A ; 404 pour une agence d'une autre organisation |

**Régler** (bouton du détail, `POST …/inter-agences/{B}/{A}/reglements`) :

- lignes « À verser » cochées par défaut (quel que soit le filtre de statut) ; les lignes réservées,
  en cours ou versées ne sont jamais proposées ;
- montant du règlement affiché en lecture seule, **jamais envoyé** : le serveur le recalcule ;
- support d'origine : supports d'agence de B, actifs et validés, avec leur solde — **jamais une caisse
  d'agent** (les espèces des agents sont d'abord versées à la caisse de l'agence) ; bouton désactivé
  si le solde est insuffisant, refus réel côté serveur à l'envoi ;
- création et envoi dans **une transaction** : un échec (solde, double règlement, support refusé)
  ne laisse aucun règlement ni brouillon ;
- autorisation serveur `MouvementFondsPolicy::regler` (`tresorerie.create` + `tresorerie.envoyer` +
  affectation à B, admin : toute l'organisation) ; `peut_regler` ne sert qu'à l'affichage.

**Réception** : écran Mouvements existant (A choisit le support qui a reçu les fonds). Un règlement y
apparaît « Règlement inter-agences » avec « N encaissements » : la liste des encaissements réglés et
un lien vers le détail de la dette. Le badge « mouvements à confirmer » compte les règlements envoyés
vers les agences de l'utilisateur (`HandleInertiaRequests::mouvementsFondsAConfirmer()`).

**Fiche commande** (Ventes et Distributions) : pour un encaissement reçu ailleurs, l'historique
indique « Encaissé à B », le statut du reversement et la référence du règlement
(`DetteInterAgencesService::reversements()`, une requête). Rien ne change pour un encaissement dans
l'agence de la commande.

## Annulation

- Encaissement **non engagé** dans un règlement : suppression (permission
  `ventes.annuler_exceptionnel`) ou annulation exceptionnelle possibles ; les deux pièces sont
  contrepassées, la dette disparaît.
- Encaissement **engagé** dans un règlement actif, même en brouillon : suppression et annulation
  exceptionnelle **refusées** (garde du modèle `EncaissementVente::deleting`, message dans
  `AnnulationExceptionnelleService`). Pour un brouillon : annuler d'abord le règlement. Après
  versement : relèvera d'un futur mécanisme de remboursement/régularisation.

## Traçabilité des agences — rapports et historique (lot 2)

- **Rapport d'activité / Ma situation** (`RapportActiviteService`, exports Excel et PDF) : colonnes
  « Créée à » (agence de la commande) et « Encaissée à » (agence qui a reçu l'argent) dans les ventes,
  encaissements, dettes clients et Mobile Money. Le bloc Encaissements suit l'agence d'encaissement et
  distingue les encaissements « pour d'autres agences (à reverser) » ; ventes et créances restent à
  l'agence de la commande. Cf. [rapports.md](rapports.md).
- **Historique de la commande** : l'entrée « Encaissement ajouté » affiche « Encaissé à : {agence} »
  (nom), jamais l'identifiant technique — y compris pour les entrées déjà enregistrées (traduction à
  l'affichage, l'audit garde l'identifiant). L'historique des encaissements de la facture affiche
  toujours l'agence d'encaissement, et le reversement quand elle diffère de l'agence de la commande.

## Hors lots 1 et 2

- Situation de trésorerie (colonnes À verser / À recevoir) et financement (disponible de B diminué
  de ce qu'il doit : `DetteInterAgencesService::aVerserParSite()`), E2E — lot 3.
- Les sorties d'argent de B ne sont pas bloquées par la dette (décision du 29/09/2026).
