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

**Révision UX du 05/09/2026 (troisième révision, même jour)** : l'aperçu n'affiche plus qu'un seul
mot — « Enlèvement » ou « Livraison » — sans phrase explicative. L'ancien libellé « Enlèvement
usine — le client retire lui-même la marchandise... » est retiré : le terme « Enlèvement » reste
volontairement générique (le retrait peut avoir lieu à l'usine, au dépôt ou sur un autre point),
pas seulement en sortie d'usine.

**Révision UX du 06/09/2026** : ce mot n'est plus affiché sous le champ véhicule, mais dans un
badge dédié « Mode », au même niveau que le badge « Nature de l'opération » déjà existant sur
`Ventes/Create.vue` — jamais sous le véhicule, qui n'est qu'une des deux sources d'info de ce
badge (l'autre étant le type de client). Le badge « Nature du client », lui, est retiré de ce même
bloc (redondant : la nature du client est déjà affichée entre parenthèses dans le sélecteur Client
depuis la révision du 05/09/2026 ci-dessus, pour toutes les natures) — le badge « Mode » ne le
remplace donc que pour Grossiste, seul type de client concerné par ce champ ; les autres natures de
client n'ont plus de second badge à côté de « Nature de l'opération ». `Ventes/Edit.vue` n'a pas ce
bloc de badges et n'est donc pas concerné par ce changement.

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

**Révision UX du 06/09/2026** : dans ce tableau de prix (lecture et édition), les deux colonnes
sont libellées **« Prix enlèvement »** / **« Prix livraison »** — un libellé local à
`Clients/Show.vue` (`prixModeLabel()`), volontairement distinct de `ModeRemiseGrossiste::label()`
(« Enlèvement » / « Livraison ») qui décrit le **mode** d'une commande, pas un prix. Le mot
« usine » (ancien libellé `Enlèvement usine` hérité de l'énum) n'est plus affiché ici, cohérent
avec la révision UX du 05/09/2026 ci-dessus qui l'avait déjà banni de l'aperçu Vente.

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
- `tests/Feature/CommandeVenteGrossisteCommissionTest.php` (règle COMM-008 ; le test Externe couvre
  désormais la généralisation COMM-009, cf. section ci-dessous)
- `tests/Feature/CommandeVenteGrossisteModeEtFallbackTest.php` (bout-en-bout HTTP `ventes.store` :
  mode dérivé du véhicule dans les deux sens, `mode_remise_grossiste` soumis dans la requête sans
  effet, tarif spécial appliqué, repli prix normal, K2 ne récupère jamais le tarif de K1, tarif
  spécial Livraison avec véhicule)

## Chantier 2A (fait le 05/09/2026) — généralisation de COMM-008

L'éligibilité par bénéficiaire (Propriétaire/Livreur/Consultant/Site indépendants) a été
généralisée à tous les types de client — voir COMM-009/COMM-010 dans `docs/commissions.md`. Le
correctif COMM-008 ci-dessus n'est donc plus une exception scopée à Grossiste + Enlèvement : c'est
désormais un cas particulier de la règle générale (`$estGrossisteSansVehicule` a été retiré de
`CommissionEnveloppeGenerator::genererPourCommandeVente()`, son comportement est le cas général).
Le chantier 2B (cadence de paiement par cible, ex: Livreur payable dès réception / Consultant
payable après encaissement client) reste hors périmètre.

## Chantier « Transfert grossiste » (fait le 05/09/2026) — nouveau processus de commission

**Corrige un point de COMM-008/2A** : une livraison Grossiste (véhicule de flotte) n'utilise plus
le barème Vente. Elle utilise désormais un processus de commission dédié et indépendant,
`CommissionProcessus::CODE_TRANSFERT_GROSSISTE` — voir COMM-011/COMM-012 dans
`docs/commissions.md` pour le détail technique complet (routage, garde-fou, UI, rétrocompatibilité).

Résumé de la règle :

- **Grossiste + Livraison** (véhicule de flotte, `mode_remise_grossiste = LIVRAISON`) →
  `CODE_TRANSFERT_GROSSISTE` — un processus à part, jamais Vente ni Transfert logistique (ses
  bénéficiaires diffèrent des deux, notamment le Site, commissionnable ici mais jamais sur un
  transfert logistique interne). Applicable uniquement aux véhicules qui font de la logistique
  (`livraison_logistique = true`) — même usage que Transfert logistique, jamais un usage propre.
- **Grossiste + Enlèvement** (aucun véhicule) → reste sur `CODE_VENTE`, inchangé : Transfert
  grossiste n'a de sens qu'avec un véhicule/une équipe de logistique, qu'un Enlèvement n'a
  structurellement jamais.
- Aucun repli automatique de barème (contrairement à `distribution_client`) : une organisation doit
  configurer explicitement l'onglet « Transferts grossistes » (Paramètres > Commissions) — sans
  quoi la création d'une commande Grossiste + Livraison est **bloquée** avec un message explicite,
  jamais une commission silencieuse à 0.
- Une équipe dont le véhicule fait de la logistique peut désormais avoir des montants fixes
  différents pour Transfert logistique ET Transfert grossiste sur la même catégorie, simultanément
  (même mécanique que Vente/Transfert logistique déjà en place, aucune migration nécessaire).

## Chantier « Réception Grossiste » (fait le 06/09/2026) — cycle de vie, pas seulement commission

**Corrige un point resté ouvert par le chantier « Transfert grossiste »** : ce dernier ne touchait
QUE le processus de commission — la commande elle-même continuait de suivre le workflow d'une
vente classique, passant en LIVREE **automatiquement au premier encaissement**
(`CommandeVenteService::passerEnLivree()`), exactement comme n'importe quelle vente sans réception.
Or une livraison Grossiste transporte une marchandise qui doit être acceptée par le client avant
que la mission logistique soit considérée réalisée — même logique métier que `distribution_client`
(COMM-004), qui avait déjà ce garde-fou depuis le 30/08/2026.

Diagnostic vérifié avant implémentation (deux affirmations relayées, une seule confirmée) :
- ✅ **Confirmé** : Grossiste + Livraison n'avait aucune étape de réception — `FactureVente` était
  déjà correcte (voir point suivant), mais le statut de la commande passait en LIVREE sans qu'aucun
  écart de quantité ne soit jamais recueilli, contrairement à `distribution_client`.
- ❌ **Infirmé** : la crainte que la facture puisse être associée au livreur plutôt qu'au Grossiste
  était sans fondement dans cette base de code — `FactureVente` n'a pas de colonne `client_id` ;
  son client est toujours résolu via `commande_vente_id → CommandeVente::client_id`, un champ
  structurellement indépendant de `vehicule_id`/l'équipe de livraison. Aucun changement nécessaire
  sur ce point.

Décision produit du 06/09/2026 (ne modifie PAS COMM-005 : `nature_operation` reste `VENTE_STANDARD`
pour un Grossiste, livré ou non — la fusion identitaire Grossiste/Distributeur reste refusée,
seul le MÉCANISME de réception est désormais partagé) :

- `App\Models\CommandeVente::requiertReceptionExplicite(): bool` — nouvelle source de vérité
  unique remplaçant partout l'ancien test `nature_operation === DISTRIBUTION_CLIENT` :
  `nature_operation === DISTRIBUTION_CLIENT || mode_remise_grossiste === LIVRAISON`. Utilisée par
  `CommandeVenteService` (guard de `validerReception()`, ex-`validerReceptionDistribution()`, et
  garde-fou anti-auto-LIVREE dans `EncaissementVenteController`), `CommandeVentePolicy` (méthode
  renommée `validerReception()`), `CommissionTriggerService` (`onChargementValide()`/
  `onFactureVenteEncaissee()`/`onFactureVenteEncaissementRetire()` deviennent des no-op pour ces
  commandes) et `CommissionEnveloppeGenerator::contexteDepuisCommandeVente()` (calcule désormais
  sur `quantite_livree`, jamais `quantite_chargee`, pour toute commande à réception explicite).
- **Conséquence sur le déclenchement de la commission Transfert grossiste** (chantier précédent,
  même jour) : elle ne naît plus au chargement mais à la validation de réception —
  `CommissionTriggerService::onReceptionValidee()` (ex-`onReceptionDistributionValidee()`, renommé
  car partagé désormais). Décision produit explicite, tranchée en faveur de la cohérence avec
  `distribution_client` plutôt que de garder le comportement du chantier précédent.
- **Frontend** : `resources/js/pages/Ventes/partials/ReceptionDialog.vue` (déjà écrit pour
  Distribution, jamais branché sur Ventes/Show.vue) est réutilisé tel quel — seul son texte
  d'introduction a été généralisé (« le client » plutôt que « le distributeur »). `Ventes/Show.vue`
  gagne une étape « Réception » dans sa timeline, un bouton « Valider la réception » (desktop +
  menu mobile) et les colonnes Reçue/Écart/Motif dans le tableau des lignes — tous conditionnés à
  `commande.mode_remise_grossiste === 'livraison'` (`requiertReception`, calculé côté Vue), jamais
  affichés pour une vente classique ni un Grossiste + Enlèvement. `Distributions/Show.vue` reste
  inchangé (page scopée à `distribution_client`, le nouveau cas Grossiste s'affiche sur
  `Ventes/Show.vue`, jamais ici — cf. le routage déjà existant de `CommandeVenteController::show()`
  sur `nature_operation`).
- **Backend déjà générique, aucune duplication** : `CommandeVenteController::show()` alimentait
  déjà `Ventes/Show`/`Distributions/Show` avec exactement le même payload (`mode_remise_grossiste`,
  `reception_validee_at`, `can_valider_reception`, lignes avec `quantite_livree`/
  `type_ecart_reception`/`ecart_livraison`) — seul le composant Vue rendu diffère. Aucune
  modification backend de mapping n'a été nécessaire pour exposer la réception à `Ventes/Show.vue`.
- **Rétrocompatibilité** : aucune migration de données. Une commande Grossiste + Livraison déjà en
  LIVRAISON_EN_COURS au moment du déploiement suit désormais le nouveau chemin (réception
  obligatoire) dès son prochain encaissement/action — pas de bascule silencieuse d'un historique
  déjà clôturé, qui reste inchangé.
- Tests : `tests/Feature/CommandeVenteGrossisteCommissionTest.php` (le chargement seul ne génère
  plus de commission, la réception devient l'étape requise, montants exacts vérifiés après
  réception), `tests/Feature/CommissionMoteurGeneriqueMultiProcessusTest.php` (coexistence Transfert
  logistique/Transfert grossiste sur la même équipe, mise à jour pour inclure la réception).
