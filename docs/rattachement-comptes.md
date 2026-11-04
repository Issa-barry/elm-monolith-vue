# Rattachement des comptes aux parrains et aux consultants

Relie un compte utilisateur à une activité parrain ou consultant, pour que cette personne consulte ses
propres données. Décision : [ADR 0050](adr/0050-rattachement-manuel-comptes-parrain-consultant.md).

L'inscription, la connexion et le code OTP ne changent pas. Les livreurs, les propriétaires et les clients
gardent leur reconnaissance actuelle.

Compte unique ([ADR 0055](adr/0055-compte-unique-back-office-espace-client.md)) : le compte d'un agent du
back-office se rattache comme les autres. Il garde ses rôles ; l'espace client lui est ouvert avec ses
identifiants habituels, et le rattachement lui donne les données de la fiche.

## Règles métier (IDs)

- **COMPTE-001 — Rattachement manuel.** Un responsable relie un compte à une fiche depuis le back-office
  quand le rattachement automatique (COMPTE-023) ne s'applique pas.
- **COMPTE-002 — Vérification avant confirmation.** Le numéro n'étant pas vérifié à l'inscription, le
  compte proposé peut appartenir à quelqu'un d'autre. Le responsable contrôle le nom saisi, le numéro, la
  date d'inscription et les rôles, et confirme l'identité auprès de la personne.
- **COMPTE-003 — Refus.** Compte désactivé ou non activé, autre organisation ; fiche désactivée ou reliée à un autre compte ; compte déjà relié à un autre parrain ou à un autre
  consultant, ou déjà interlocuteur de la même entreprise ; prestataire qui n'est pas consultant ; entreprise
  rattachée directement ou désactivée.
- **COMPTE-004 — Cumul.** Un compte peut être parrain, consultant, propriétaire et interlocuteur de plusieurs
  entreprises à la fois, sans mélange des données.
- **COMPTE-005 — Accès immédiatement révocable.** L'accès est recalculé à chaque requête depuis `user_id`,
  jamais depuis le téléphone. Détacher, désactiver un interlocuteur ou lui retirer l'autorisation coupe
  l'accès sans supprimer le compte.
- **COMPTE-006 — Interlocuteur.** Personne physique qui représente une entreprise tierce. Elle n'est jamais
  bénéficiaire : les commissions restent attribuées au prestataire de l'entreprise. L'accès exige
  `acces_commissions`, désactivé par défaut. Il se limite aux prestataires de type consultant, jamais aux
  achats d'une société également fournisseur.
- **COMPTE-007 — Historique.** Un parrain ou un consultant désactivé ou remplacé garde l'accès à son propre
  historique, sans les opérations de ses remplaçants.
- **COMPTE-008 — Aucune fusion, aucun lot.** Aucune fiche `Personne` ni aucun compte n'est fusionné ou
  supprimé, et aucun rattachement n'est fait en masse. L'organisation d'un compte auto-inscrit est renseignée
  au rattachement et conservée au détachement.
- **COMPTE-009 — Traçabilité.** Chaque rattachement et détachement est écrit dans `audit_logs`
  (`compte_rattache`, `compte_detache`) avec son auteur.

## Modèle

| Élément | Rôle |
|---|---|
| `parrains.user_id` | Compte du parrain |
| `prestataires.user_id` | Compte d'un consultant personne physique, toujours vide pour une entreprise |
| `entreprise_interlocuteurs` | Entreprise, `personne_id`, `user_id`, `acces_commissions`, `is_active` ; unique par entreprise et personne ; jamais supprimé, désactivé |
| `RattachementCompteService` | Rattachement manuel : `compteParTelephone()`, `apercu()`, `motifRefus()`, `rattacher()`, `detacher()` |
| `RattachementAutomatiqueService` | Rattachement automatique d'une fiche à la même Personne (COMPTE-023) : `analyser()`, `appliquer()`, `rattacherPersonne()` |
| `ActivitesCompteResolver` | Parrain et consultants accessibles à un compte, source unique des contrôles d'accès |

`user_id` n'est pas mass-assignable sur ces trois modèles.

## Permissions

| Permission | Rôle |
|---|---|
| `parrains.read` | Liste et fiches des parrains, à lui seul sans aucun montant |
| `rattachements-comptes.gerer` | Rattacher ou détacher un compte, ce qui donne accès à ses commissions |

Accordées à `admin_entreprise` à la migration. Les autres rôles les reçoivent dans `/backoffice/roles`. La
création et la modification d'un parrain restent liées au véhicule (PARRAIN-005, PARRAIN-006). Rien ne
désactive un parrain aujourd'hui : un tel droit n'aurait aucun effet, et il faudrait d'abord décider ce que
deviennent ses commissions.

## Écrans du back-office

Contacts → Parrains (module Véhicules).

- **COMPTE-010 — Liste** (`/backoffice/parrains`). Nom, téléphone, véhicules, compte, reste à payer, statut ;
  filtres Agence, Statut, Compte et recherche. Un parrain relève d'une agence s'il y a un véhicule parrainé ou
  une fiche de paiement.
- **COMPTE-011 — Fiche** (`/backoffice/parrains/{id}`). Onglets Informations, Compte (avec l'historique des
  rattachements), Véhicules et Commissions.
- **COMPTE-012 — Périmètre.** Sans vision toutes agences (ADR 0025), l'utilisateur ne voit que les parrains de
  ses agences, et sur leur fiche seulement les véhicules, fiches et paiements de ces agences.
- **COMPTE-013 — Commissions en lecture seule.** L'onglet et le reste à payer exigent `comptabilite.read` et le
  module Comptabilité, comme l'écran Comptabilité → Commissions → Parrains. Les montants sont lus sur les fiches
  de paiement (PARRAIN-017), sans aucun calcul ni écriture. Le paiement reste sur la fiche de paiement.
- **COMPTE-014 — Rattachement.** La fenêtre cherche le numéro du parrain, ou un autre numéro saisi, et affiche
  le compte trouvé et son éventuel motif de refus. La recherche ne relie rien. Le serveur exige la confirmation
  du responsable (`identite_verifiee`) et revérifie tous les refus. Un autre numéro efface le compte proposé.
- **COMPTE-015 — Compte d'une autre organisation.** Seul le motif de refus s'affiche, jamais son identité.
- **COMPTE-016 — Détachement.** Confirmation explicite : l'accès est coupé, le compte et ses autres activités
  restent. La trace garde le nom et le numéro du compte détaché.

## Espace parrain

Contrat d'API : [api-espace-parrain-contract.md](api-espace-parrain-contract.md). Écrans Nuxt sous
`/espace-client/parrain` : accueil, véhicules, commissions, paiements.

- **COMPTE-017 — Accès.** L'espace client est ouvert à tout compte actif (ADR 0055) ; le rattachement décide de
  l'accès aux données parrain à chaque requête. Sans parrain rattaché, l'API répond `404`. Aucun
  paramètre ne désigne un parrain.
- **COMPTE-018 — Lecture seule et périmètre.** Le parrain ne voit que ses propres parts et versements, sur toutes
  les agences. Jamais le client, le montant de la commande, la part des autres bénéficiaires, l'équipe ni la trésorerie.
- **COMPTE-019 — Montants.** Totaux issus de `CommissionKpiBuckets` et `calculerResume()`, sans calcul propre ni
  écriture. Le reste à payer ne compte que les parts passées par la validation de leur période, comme au back-office.
  Après validation, il est égal au reste de ses fiches de paiement (COMPTE-013). Avant, le montant figure en attente
  dans l'espace parrain alors que la fiche du back-office le compte déjà dans son reste.
- **COMPTE-020 — Véhicules.** Seuls les véhicules parrainés actuellement sont listés. Un parrain remplacé garde ses
  commissions, sans le véhicule (PARRAIN-012).
- **COMPTE-021 — Navigation.** Un parrain seul arrive sur l'accueil parrain et sa barre mobile reste celle de cet
  espace. Avec une autre activité, le menu habituel ne change pas : une carte de l'accueil ouvre l'espace parrain, et sa
  barre mobile propose le retour. Sur ordinateur, le menu latéral affiche une section Espace parrain.
- **COMPTE-022 — Détachement en cours de session.** Les écrans indiquent que le compte n'est plus rattaché et les
  menus sont recalculés, sans déconnexion.

## Rattachement automatique

Décision : [ADR 0056](adr/0056-rattachement-automatique-meme-personne.md). `RattachementAutomatiqueService`,
déclenché par `RattachementAutomatiqueObserver`.

- **COMPTE-023 — Même Personne.** Une fiche livreur, propriétaire, parrain, consultant personne physique ou
  interlocuteur sans compte est reliée automatiquement au compte dont l'identité recouvre sa Personne : la fiche
  Personne du compte, ou celle d'une autre fiche déjà reliée au compte dans l'organisation. Pour un parrain, le
  numéro de téléphone du compte suffit aussi, sans vérification de sa possession (OTP : chantier ultérieur).
- **COMPTE-024 — Moments.** À la création d'une fiche ou au changement de sa Personne ou de son numéro ; quand un
  compte est créé, activé (validation de l'e-mail), ou reçoit une fiche Personne ou un numéro ; quand une activité
  est reliée au compte : les autres fiches de la même Personne suivent. Après validation de la transaction ; un échec
  laisse la fiche au manuel.
- **COMPTE-025 — Restent au manuel.** Numéro identique pour un consultant ou un interlocuteur seul, plusieurs comptes
  pour la même Personne, compte sans organisation dont le numéro figure sur des fiches parrain de plusieurs
  organisations, refus de COMPTE-003, compte déjà relié à une autre fiche livreur ou propriétaire, et compte qu'un
  responsable a détaché de cette fiche : un détachement n'est jamais annulé automatiquement.
- **COMPTE-026 — Trace.** `compte_rattache` sans auteur, avec `meta.automatique`, `meta.source` (`fiche`, `compte`,
  `activite_reliee`, `regularisation`) et `meta.preuve` (Personne commune, ou numéro non vérifié). La fiche parrain
  l'affiche « Rattachement automatique ».
- **COMPTE-027 — Régularisation.** `php artisan comptes:regulariser-rattachements` : simulation par défaut (bilan par
  activité et détail nominatif, `--csv=fichier` pour l'export, `--organization=` pour restreindre). `--apply` relie
  les seules correspondances certaines après confirmation (`--force` pour la passer), chacune re-vérifiée dans sa
  transaction. Relancée, la commande ne refait rien. Sauvegarder la base avant `--apply`.
- **COMPTE-028 — Correction exceptionnelle.** Le rattachement manuel (COMPTE-014) n'est plus le parcours normal : le
  bouton « Rattacher manuellement », secondaire, reste réservé à `rattachements-comptes.gerer` pour les cas laissés au
  manuel. Une fiche parrain sans compte affiche pourquoi : aucun compte pour cette personne (la fiche sera reliée dès
  que son compte sera connu), ou « Rattachement automatique impossible » avec le motif de COMPTE-025, sans jamais
  l'identité du compte trouvé.

## Avancement

- **Lot 1 — socle, livré le 02/11/2026** : migrations, modèles, permissions, service et résolveur. Rien de
  visible pour les utilisateurs.
- **Lot 2 — parrains au back-office, codé le 02/11/2026** : liste, fiche, rattachement et détachement.
- **Lot 3 — espace parrain, codé le 02/11/2026** : API, `context.parrain_id` de `/me`, écrans Nuxt.
- Lot 4 : interlocuteurs au back-office. Lot 5 : espace consultant.
