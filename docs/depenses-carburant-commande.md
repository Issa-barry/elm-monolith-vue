# Dépense de carburant — commande de référence

Une dépense dont le type exige une commande (en pratique : **Carburant**) est rattachée à la
vente ou au transfert logistique qui a fait rouler le véhicule. **La commande est obligatoire,
sans exception** (depuis le 17/10/2026). Décision : [ADR 0031](adr/0031-rattachement-depense-carburant-commande.md).

## Règles métier (IDs)

- **DEPCARB-001** — Le réglage est porté par le **type de dépense** : case « Commande
  obligatoire » (`depense_types.commande_obligatoire`), dans Dépenses → Types. Elle n'existe que
  pour les types de catégorie **Véhicule** (refus serveur sinon) : il faut un véhicule pour
  retrouver la commande. Les autres types ne changent pas.
- **DEPCARB-002** — Une dépense d'un tel type porte **soit** une commande de vente
  (`commande_vente_id`, préfixes `VTE`/`DST`), **soit** un transfert logistique
  (`transfert_logistique_id`, `TRF`). Jamais deux commandes, jamais aucune. Contrôlé à chaque enregistrement
  (création, modification, brouillon compris) par `ValideRattachementCommande`.
- **DEPCARB-003** — La commande est **revérifiée côté serveur** (`CommandeCarburantService::verifier()`) :
  même organisation, livrée par le véhicule de la dépense (`vehicule_id`), non annulée
  (`annulee`, `annulee_erreur_saisie`, transfert `annule`), **du jour de la dépense** et, si la
  dépense porte une agence, **de cette agence**.
  - *Du jour* : créée ce jour-là (jour de sa référence), ou livrée (`livree_at`, vente) / partie
    (`date_depart_reelle`, transfert) ce jour-là — une précommande créée la veille reste trouvable
    le jour du voyage.
  - *De l'agence* : vente de cette agence (`site_id`) ; transfert dont l'agence est la source ou la
    destination. Sans agence sur la dépense (« Aucun site spécifique »), pas de filtre d'agence.
- **DEPCARB-004** — **Une seule commande de référence** par dépense. Le plein peut avoir servi à
  plusieurs commandes de la tournée ; la commande retenue sert de référence, sans répartition du
  coût. Une même commande peut être référencée par plusieurs dépenses (deux pleins pour un long
  trajet) : l'écran le signale (« Déjà rattachée à N dépense(s) ») sans le bloquer.
- **DEPCARB-005** — **Aucune exception** : un plein sans commande (plein de début de semaine,
  retour à vide, trajet au garage…) ne peut pas être saisi comme dépense de ce type. Décision du
  17/10/2026, qui remplace l'exception motivée ouverte le 16/10 (cf. ADR 0031). La dépense suit le
  **circuit de validation habituel** (cf. [depenses-validation.md](depenses-validation.md)).
  Un `motif_sans_commande` éventuellement envoyé est ignoré et effacé à l'enregistrement.
- **DEPCARB-006** — **Historique** : aucune dépense existante n'est modifiée. Une dépense déjà
  soumise ou validée avant la règle reste validable telle quelle, y compris une dépense
  enregistrée « sans commande » pendant que l'exception existait (16-17/10/2026), toujours
  signalée comme telle. Un brouillon antérieur — même porteur d'un motif d'exception — doit être
  rattaché à une commande avant d'être soumis (`SoumettreDepenseController`).
- **DEPCARB-007** — Les frais carburant saisis sur la **fiche véhicule** (`vehicule_frais`) sont
  hors de cette règle (circuit distinct).
- **DEPCARB-008** — Le rattachement ne change pas la comptabilisation : une dépense véhicule reste
  déduite de la commission du propriétaire (`PeriodeCalculatorService`) comme avant.

## Choix de la commande (aide à la saisie)

`GET /backoffice/depenses/commandes-carburant?vehicule_id=&date_depense=&site_id=` —
`CommandeCarburantService::candidats()`, réservé à `depenses.create` ou `depenses.update`,
véhicule et agence de l'organisation uniquement. Renvoie **exactement** les commandes
rattachables (DEPCARB-003) : celles du véhicule, du jour et de l'agence choisis sur le formulaire,
non annulées. La liste se recharge dès que le véhicule, la date ou l'agence change.

L'écran affiche directement un **champ de recherche** (même présentation que la recherche
globale de l'en-tête) : au focus, les commandes s'affichent dessous ; la saisie les filtre au fil
de la frappe (sans accents ni casse, tous les mots saisis), flèches + Entrée ou clic pour choisir.
Pas de bouton de création : on ne fait que retrouver une commande existante. Le filtre porte sur :

- la **référence complète** (`VTE-161026-002`, ou ancienne `CMD-…`/`TR-…`) ;
- le **code abrégé `JJ-NNN`** (`16-002`) : jour et numéro de la référence `PRÉFIXE-JJMMAA-NNN`,
  calculé par le serveur pour chaque commande ;
- le **client** (vente) ou le **trajet** agence source → destination (transfert).

Les compteurs étant propres à chaque préfixe (cf. [references-metier.md](references-metier.md)),
`16-002` peut afficher à la fois `VTE-161026-002` et `TRF-161026-002` : l'agent choisit, le système
ne choisit jamais à sa place, même quand une seule commande est listée. Si le véhicule n'a aucune
commande sur la période, un message l'indique ; la dépense passe alors par l'exception motivée.

## Écrans

- **Dépenses → Nouvelle / Modifier** : bloc « Commande de référence » sous les détails quand le
  type l'exige (`CommandeCarburantField.vue`). Changer de véhicule ou de date recharge la liste et
  retire la commande choisie si elle n'y figure plus. Boutons d'enregistrement inactifs tant qu'aucune
  commande n'est choisie. Si aucune commande ne correspond, l'écran invite à vérifier le véhicule,
  la date et l'agence.
- **Date** : raccourcis « Aujourd'hui » / « Hier » sous le champ (`DateRaccourcis.vue`), calendrier
  conservé. Volontairement **aucun raccourci de période** (« Cette semaine »…) : une dépense a une
  date précise et sa commande doit être de ce jour (DEPCARB-003). Changer la date recharge les
  commandes en gardant le véhicule.
- **Détail** : ligne « Commande » (lien vers la vente ou le transfert si l'utilisateur peut les
  consulter), ou pour l'historique « Sans commande (exception) » + motif, ou « Non renseignée ».
- **Liste** (bureau et mobile) : référence de la commande, ou « Sans commande » en orange
  (historique uniquement).

## Données

| Colonne | Table | Rôle |
|---|---|---|
| `commande_obligatoire` | `depense_types` | Le type exige une commande ou une exception (DEPCARB-001). |
| `commande_vente_id` | `depenses` | Vente / distribution de référence (FK, `null` à la suppression). |
| `transfert_logistique_id` | `depenses` | Transfert logistique de référence (FK, `null` à la suppression). |
| `motif_sans_commande` | `depenses` | Historique : motif d'une dépense enregistrée « sans commande » les 16-17/10/2026. Plus jamais écrit (colonne conservée, aucune suppression de données). |

Migration [`2026_10_16_120000_add_rattachement_commande_to_depenses`](../database/migrations/2026_10_16_120000_add_rattachement_commande_to_depenses.php) :
ajoute les colonnes et coche `commande_obligatoire` sur les types existants de catégorie véhicule
dont le code commence par `carburant` (code généré depuis le libellé : `carburant_vehicule` du
seeder, `carburant` créé à la main…). Un type carburant nommé autrement (« Gasoil »…) est à
cocher dans Dépenses → Types.
