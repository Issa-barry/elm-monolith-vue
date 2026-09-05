# Client Grossiste — nature, mode de remise et tarification (05/09/2026)

## Contexte métier

Un Grossiste achète en grande quantité et peut, commande par commande :
- **venir récupérer lui-même la marchandise à l'usine** (Enlèvement) ;
- **se faire livrer par ELM** (Livraison).

Le même client peut être en Enlèvement sur une commande et en Livraison sur une autre — le mode
n'est donc jamais une caractéristique fixe du client, il est porté par la commande.

Le tarif appliqué dépend de la **catégorie commerciale du produit** (ex: Bouteille d'eau, Sachet
d'eau — catégories déjà existantes dans le catalogue, cf. `Categorie`) et du **mode de remise**,
jamais du produit individuellement.

## Nature client

`App\Enums\ClientType::GROSSISTE` — 4ᵉ case, ajoutée volontairement comme nature distincte plutôt
que comme sous-type de `DISTRIBUTEUR` (qui porte une signification technique différente, liée à
`NatureOperation::DISTRIBUTION_CLIENT`, cf. `docs/commissions.md`). Le `match` exhaustif sur
`ClientType` (`PrixVenteNatureResolver`, etc.) force un traitement explicite à chaque point de
branchement plutôt qu'un héritage implicite du comportement Distributeur.

- Cashback : facultatif par défaut (comme Externe/Distributeur), aucune règle spécifique ajoutée à
  `CashbackEligibiliteService` — celui-ci ne force le cashback que pour `REVENDEUR`.
- `NatureOperation` : un Grossiste, livré ou non, dérive toujours `VENTE_STANDARD`
  (`NatureOperation::deriverParDefaut()` ne réserve `DISTRIBUTION_CLIENT` qu'à `DISTRIBUTEUR`).
  Décision produit du 05/09/2026 : ne pas mélanger Grossiste et Distributeur sous le même tag de
  reporting/processus.

## Mode de remise — `App\Enums\ModeRemiseGrossiste`

**Révision du 05/09/2026 (deuxième décision produit le même jour)** : le mode n'est plus un choix
utilisateur indépendant (les boutons radio Enlèvement/Livraison ont été retirés du formulaire de
vente). Le champ **véhicule** est l'unique source de vérité :

- **Véhicule sélectionné ⇒ `LIVRAISON`.**
- **Aucun véhicule ⇒ `ENLEVEMENT`.**

`CommandeVenteController::deriverModeRemiseGrossiste(?string $vehiculeId, ?Client $client): ?ModeRemiseGrossiste`
(appelé depuis `store()` et `update()`, avant `buildLignesDataAndTotal()`) calcule
`CommandeVente::mode_remise_grossiste` **uniquement** à partir de la présence de `vehicule_id` —
un `mode_remise_grossiste` éventuellement soumis dans la requête est ignoré : il n'existe aucun
chemin où le client peut décorréler le mode du véhicule. L'état incohérent
« ENLEVEMENT + véhicule » ou « LIVRAISON + sans véhicule » n'est donc pas seulement validé, il est
rendu **impossible par construction** — il n'y a plus de second champ à faire diverger.

- `ENLEVEMENT` (pas de véhicule) ⇒ la commande route naturellement vers
  `CommandeVenteService::creerFactureDirecte()` (vente directe existante, réutilisée telle quelle :
  pas d'étape de chargement, décrément de stock immédiat, statut direct FACTURATION).
- `LIVRAISON` (véhicule choisi) ⇒ la commande suit le workflow flotte standard
  (`confirmer()` → chargement → livraison), strictement inchangé.

Aucun champ de mode générique n'a été introduit pour les autres natures de client : c'est un champ
strictement scopé à Grossiste, pour ne pas élargir la surface de changement au-delà du besoin.

**Révision UX du 05/09/2026 (troisième révision, même jour)** : sous le champ véhicule, l'aperçu
n'affiche plus qu'un seul mot — « Enlèvement » ou « Livraison » — sans phrase explicative. L'ancien
libellé « Enlèvement usine — le client retire lui-même la marchandise... » est retiré : le terme
« Enlèvement » reste volontairement générique (le retrait peut avoir lieu à l'usine, au dépôt ou
sur un autre point), pas seulement en sortie d'usine.

## Tarification — catégorie × mode × CLIENT

**Révision du 05/09/2026** : le premier jet livrait un tarif organisation-wide (une seule grille
partagée par tous les Grossistes). Décision produit corrigée le jour même — chaque Grossiste
négocie son propre tarif, deux clients peuvent avoir des prix différents pour la même
catégorie/mode. La clé tarifaire réelle est **client + catégorie + mode**, jamais
**organisation + catégorie + mode**.

Table `categorie_tarifs_grossiste` (`organization_id`, `client_id`, `categorie_id`, `mode`,
`prix`, unique par **client_id+categorie_id+mode**) et modèle `App\Models\CategorieTarifGrossiste`
(relations `client()`, `categorie()`, méthode statique `gridForClient()` — seule lecture réutilisée
par `ClientController::show()` et `CategorieTarifGrossisteController::forClient()`). Volontairement
**pas** une nouvelle colonne `prix_grossiste` sur `produit_variantes` : contrairement à
`prix_externe`/`prix_revendeur`/`prix_distributeur` (tarif par nature de client, au grain
variante), le tarif Grossiste dépend de la catégorie du produit ET du client, pas de la variante.

**Révision du 05/09/2026 (deuxième décision produit le même jour)** : un tarif Grossiste configuré
est une **surcharge facultative** du prix normal, jamais une obligation. Le premier jet bloquait la
vente (`ValidationException`) dès qu'aucun tarif n'existait pour le client/catégorie/mode ; ce
blocage a été retiré — l'absence de tarif spécial ne doit jamais empêcher une vente.

`App\Services\GrossisteTarifResolver::resolve(ProduitVariante $variante, ModeRemiseGrossiste $mode, Client $client): int` —
seul point de résolution, jamais un prix envoyé par le frontend :
- Si un tarif est configuré pour **ce client précis** sur (catégorie, mode) → ce tarif est utilisé.
  Le tarif d'un **autre** Grossiste n'est **jamais** utilisé en repli, même si ce client n'a rien
  configuré (deux clients Grossiste, ex. K1 et K2, ne partagent jamais leurs tarifs négociés).
- Sinon (produit sans `categorie_id`, ou aucun tarif configuré pour ce client sur cette
  catégorie/ce mode) → **repli silencieux sur `ProduitVariante::prix_vente`** (le prix normal du
  produit, celui utilisé pour tous les autres types de client). Aucun blocage dans ce cas.
- **Bloque** (`ValidationException`) uniquement quand un tarif spécial **est** configuré mais ne
  couvre pas le coût de référence du produit (`ProduitType::champPrixReference()` — `prix_usine`
  pour Fabricable, `prix_achat` pour Achat/Vente, ignoré pour les types sans référence) — même
  principe anti-vente-à-perte que `ProduitService::validerPrixSelonType()`, appliqué ici en défense
  en profondeur au moment de la vente. Le même garde-fou s'applique côté admin
  (`CategorieTarifGrossisteController::update()`, contre tous les produits déjà rattachés à la
  catégorie) avant même d'enregistrer un tarif incohérent — un tarif absent, lui, ne déclenche
  jamais ce contrôle puisqu'il n'y a rien à valider.

`App\Services\GrossisteTarifResolver::resolveOrigine(...): PrixOrigine` renvoie `GROSSISTE` quand
le tarif spécial a été appliqué, `VENTE` quand c'est le repli sur le prix normal — tracé dans
`CommandeLigne::prix_origine_snapshot` comme pour toute autre ligne.

`CommandeVenteController::buildLignesDataAndTotal()` (et son miroir `PdvCheckoutService`) branchent
sur `client.type === GROSSISTE` **avant** toute logique `PrixVenteNatureResolver`/`PrixUsineResolver`
— ces deux resolvers ne sont jamais appelés pour une ligne Grossiste. `PdvCheckoutService::checkout()`
refuse explicitement un client Grossiste (`ValidationException`) : le tarif catégorie × mode n'a pas
de sens au comptoir, un Grossiste passe toujours par une commande de vente classique.

Administration : onglet **« Tarification »** sur la fiche du client Grossiste lui-même
(`Clients/Show.vue`, visible uniquement si `client.type === 'grossiste'`) — jamais une page
d'administration globale. Gatée par la policy Client (`clients.read`/`clients.update` + même
organisation), pas une permission séparée : les tarifs Grossiste sont un sous-résultat du client.
`CategorieTarifGrossisteController::forClient()` (JSON, `GET clients/{client}/tarifs-grossiste`)
sert aussi l'aperçu live sur `Ventes/Create.vue`/`Edit.vue`, fetché uniquement au choix d'un client
Grossiste — jamais une grille envoyée à toute création de vente, qui exposerait les tarifs négociés
de tous les Grossistes de l'organisation même pour une commande destinée à un autre client.

**Révision UX du 05/09/2026 (troisième révision, même jour)** : le sélecteur Client de
`Ventes/Create.vue`/`Edit.vue` affiche désormais la nature du client à côté de son nom — dans le
champ une fois sélectionné (`K2 (Grossiste)`) et dans chaque ligne de la liste déroulante
(`K2 — Grossiste`), pour toutes les natures (Externe/Revendeur/Distributeur/Grossiste), pas
seulement Grossiste. Réutilise `ClientType::label()` déjà exposé ailleurs (`Client::type_label`,
cf. `Clients/Index.vue`/`Show.vue`) plutôt qu'une nouvelle table de libellés — `clientsActifs()`
(source commune à `create()`/`edit()`) expose maintenant `type_label` en plus de `type`.

## Commission — voir `docs/commissions.md` (COMM-008)

Résumé : la commission de transfert logistique suit le mode (aucune en Enlèvement, normale en
Livraison), mais la commission consultant est indépendante du mode — générée dans les deux cas si
une règle active existe. Détail complet, patch du moteur et tests dans `docs/commissions.md`.

## Fichiers clés

- `app/Enums/ClientType.php`, `app/Enums/ModeRemiseGrossiste.php`, `app/Enums/PrixOrigine.php`
- `app/Models/CategorieTarifGrossiste.php` (`client_id`, `gridForClient()`), `app/Models/Client.php`
  (relation `tarifsGrossiste()`, `isGrossiste()`), `app/Models/Categorie.php` (relation
  `tarifsGrossiste()`)
- `app/Services/GrossisteTarifResolver.php` (signature avec `Client $client`, repli sur
  `prix_vente`, `resolveOrigine()`)
- `app/Http/Controllers/CommandeVenteController.php` (`deriverModeRemiseGrossiste()`,
  `buildLignesDataAndTotal()`)
- `app/Http/Controllers/ClientController.php` (`show()` embarque `tarifs_grossiste` pour CE client)
- `app/Http/Controllers/CategorieTarifGrossisteController.php` (`forClient()`, `update()`, tous
  deux scopés `Client $client`)
- `app/Services/CommandeVenteService.php`, `app/Services/CommissionTriggerService.php`,
  `app/Services/Commission/CommissionEnveloppeGenerator.php` (cf. COMM-008)
- `resources/js/pages/Clients/Show.vue` (onglet Tarification, pattern « Ajouter une ligne »,
  visible si Grossiste)
- `resources/js/pages/Ventes/Create.vue`, `Edit.vue` (mode dérivé du véhicule — aucun sélecteur,
  aperçu de prix fetché par client sélectionné avec repli visuel sur le prix normal)

## Tests

- `tests/Unit/EnumsTest.php` (ClientType/ModeRemiseGrossiste/PrixOrigine)
- `tests/Unit/GrossisteTarifResolverTest.php` (tarif spécial appliqué, repli prix normal — produit
  sans catégorie / tarif absent / tarif d'un autre client jamais réutilisé —, blocage marge
  uniquement si un tarif existe)
- `tests/Feature/CategorieTarifGrossisteTest.php` (scopé client, isolation entre deux Grossistes)
- `tests/Feature/CommandeVenteGrossisteCommissionTest.php` (règle COMM-008, non-régression Externe)
- `tests/Feature/CommandeVenteGrossisteModeEtFallbackTest.php` (bout-en-bout HTTP `ventes.store` :
  mode dérivé du véhicule dans les deux sens, `mode_remise_grossiste` soumis dans la requête sans
  effet, tarif spécial appliqué, repli prix normal, K2 ne récupère jamais le tarif de K1, tarif
  spécial Livraison avec véhicule)

## Hors périmètre (Chantier 2, non fait)

Généraliser l'éligibilité par bénéficiaire (Propriétaire/Livreur/Consultant/Site indépendants) à
tous les types de client — décision explicite du 05/09/2026 de ne pas mélanger cette refonte avec
la livraison Grossiste. Le correctif COMM-008 reste une exception scopée à Grossiste + Enlèvement.
