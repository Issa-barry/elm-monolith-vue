# ADR 0001 — Caisse dédiée à un agent : un sous-compte comptable par caisse

- **Date** : 2026-09-19
- **Statut** : accepté — phases 1 (modèle + écran Supports), 2 (routage des encaissements en
  espèces) et 3 (versement vers la caisse de l'agence) livrées le 2026-09-19 ; phase 4 (fiche
  caisse) à venir
- **Périmètre** : Comptabilité > Trésorerie (supports, situation, financement, mouvements de fonds)

## Contexte

Les ventes sont encaissées par des agents (vendeurs, caissiers). L'entreprise veut que chaque
agent ait **sa** caisse, alimentée par ses encaissements en espèces, puis que le responsable en
récupère tout ou partie vers la caisse de l'agence avec une traçabilité complète.

Le modèle existant ne le permet pas :

- `compta_ecritures` ne porte que `compte_comptable_id` + `site_id`, **pas de support de
  trésorerie**. Deux supports d'un même site sur le même compte (571000) sont indiscernables au
  grand livre et affichent le même solde (limite déjà documentée dans
  `TresorerieDisponibiliteService::situationParSupport()`).
- Un encaissement (`EncaissementVente`) n'a ni site ni support : le compte est résolu par
  `compta_mappings` (espèces → 571000), le site est celui de la facture, l'auteur est `created_by`.
- Le « disponible » d'un site (Financement des agences) additionne **tous** les supports du site.

## Décision

1. **Un sous-compte comptable propre par caisse dédiée** (571001, 571002... sous le compte racine
   571000), créé automatiquement. Le solde d'une caisse dédiée est le solde de son sous-compte,
   lu au grand livre : aucun solde parallèle.
2. **Nature dérivée de `agent_id`** (nullable) sur `compta_supports_tresorerie` : pas de colonne
   « nature » redondante. Une caisse dédiée est toujours de type Caisse.
3. **Une seule caisse dédiée active par (agent, site)**, sinon le routage automatique des
   encaissements serait ambigu.
4. **Seuls les encaissements en espèces** alimentent la caisse d'un agent (Mobile Money, virement
   et chèque gardent leurs supports habituels) — phase 2.
5. **Situation = où est l'argent** (inclut les caisses dédiées) ; **Financement = combien l'agence
   peut utiliser** (les exclut, tant que l'argent n'est pas versé et réceptionné).
6. **Versement caisse agent → caisse agence en deux étapes** (Brouillon → Envoyé → Reçu), en
   réutilisant `MouvementFonds` avec une nature interne ; **l'émetteur ne peut pas confirmer sa
   propre réception** ; contrôle de solde sous verrou — phase 3.

## Alternatives écartées

- **Dimension « support » sur `compta_ecritures`** (colonne `compte_tresorerie_id`) : plan
  comptable plus propre, mais modifie le cœur du grand livre (`EcritureComptableService`),
  demande un backfill et l'adaptation de toutes les requêtes de solde. Disproportionné pour le
  besoin (« combien y a-t-il dans chaque caisse ? »), que les sous-comptes satisfont nativement.
- **Versement immédiat en une étape** : plus simple, mais sans double contrôle ni gestion de
  litige entre l'agent et le responsable.

## Conséquences

- **Positives** : le grand livre reste inchangé ; Situation, Journal financier (filtre par
  compte) et disponible fonctionnent sans modification structurelle ; pratique SYSCOHADA
  standard (une caisse = un sous-compte).
- **Coût** : un compte par agent et par site dans le plan comptable. Numérotation plafonnée à
  571999 par organisation ; un sous-compte n'est jamais réattribué.
- **Garde-fous livrés en phase 1** : `disponiblePourSite()` et `positionFiable()` ignorent les
  caisses dédiées ; `MouvementFondsService` refuse une caisse dédiée en origine ou destination
  d'un mouvement entre agences ; un solde d'ouverture est refusé pour une caisse dédiée ;
  la suppression d'un utilisateur responsable d'une caisse active est refusée.
- **Phase 2 — routage des encaissements** (`CaisseAgentResolver`, branché dans
  `VenteComptabilisationService`) : espèces uniquement, caisse active de l'auteur de
  l'encaissement sur le site de la facture, jamais de reclassement de l'historique (date de
  l'encaissement ≥ mise en service de la caisse, et encaissement enregistré après elle — filet pour
  `ComptabiliteRattrapageCommand` ; la mise en service est la validation de la caisse depuis
  l'ADR 0002, sa création à l'origine). Aucune colonne ajoutée à `encaissements_ventes`.
- **Écart découvert en phase 2 — journal comptable** : le moteur déduit le journal d'une ligne
  *mappée*, et la ligne client de `encaissement_vente_recu` n'en porte volontairement pas (le
  journal vient de la ligne trésorerie). Avec un compte de trésorerie imposé, plus aucune ligne ne
  fournissait de journal. `EcritureComptableService::comptabiliser()` accepte donc une option de
  ligne `journal_role` (compte imposé, journal tiré du mapping de ce rôle) : opt-in, sans effet
  pour les appelants existants, et le schéma du grand livre reste inchangé. Alternative écartée :
  une ligne `compta_mappings` par caisse (`especes:{id}`), qui aurait fait apparaître les
  sous-comptes des caisses dédiées parmi les comptes proposés à la création d'un support d'agence.
- **Phase 3 — versement** : un versement est un `MouvementFonds` de nature `interne_caisses` (même
  table, statuts et écritures via le compte de transit 588000), créé et envoyé en une seule
  opération par `MouvementFondsService::verserCaisseAgent()`. La caisse de destination (caisse
  d'agence de type Caisse, même site) est fixée à l'envoi ; le solde de la caisse source est
  contrôlé au grand livre sous verrou ; l'envoyeur ne confirme ni ne conteste (sauf super admin).
  Décisions du 2026-09-19 : permission dédiée `tresorerie.verser` (un agent qui verse sa caisse ne
  doit pas pouvoir envoyer de l'argent entre agences) ; versements internes exclus de « fonds en
  transit » et de « déjà financé » ; écran Supports ouvert en lecture à `tresorerie.read`, limité à
  ses agences.
- **Exception super admin à la séparation envoi/réception** (choisie contre la recommandation
  initiale « aucun contournement ») : elle évite le blocage d'une organisation à un seul
  utilisateur habilité, mais un même compte peut alors envoyer et confirmer. Elle est bornée au
  rôle `super_admin` (pas `admin_entreprise`), portée par une règle unique
  (`MouvementFonds::separationEnvoiReceptionRespectee()`), et traçable : `sent_by` et
  `received_by` sont tous deux conservés. Le `Gate::before` du super admin neutralisant les
  policies, la règle est imposée par le service ; sans dérogation, il faut au moins deux
  utilisateurs habilités par agence.
- **Cycle de vie (ADR [0002](0002-cycle-de-vie-support-tresorerie.md))** : une caisse dédiée est
  désormais créée en **brouillon** et validée avant usage ; le routage des encaissements date sa
  mise en service à la validation (`valide_le`), plus à la création.
- **Garde-fous liés** : les indicateurs `peut_*` de l'écran Mouvements vérifient l'état du
  mouvement explicitement (corrige l'affichage de toutes les actions sur les lignes terminées pour
  un super admin) ; une caisse ne peut pas être désactivée pendant un versement Envoyé ou Contesté.
- **Espèces : caisse dédiée obligatoire (décision du 2026-09-23).** Le repli sur le compte partagé
  571000 permettait à un agent sans caisse d'encaisser en espèces sans que personne ne soit
  responsable de l'argent (cas constaté en exploitation : un manager sans caisse encaissait et la
  destination était introuvable). Un **nouvel** encaissement en espèces est désormais refusé côté
  serveur (`CaisseAgentResolver::garantirCaissePourEspeces()`, appelée par
  `StoreEncaissementVenteController`) tant que son auteur n'a pas de caisse dédiée active sur le
  site de la facture — pour tous les rôles, sans contournement. Mobile Money, virement et chèque ne
  sont pas concernés (ils ne touchent pas la caisse de l'agent). L'interface reflète la règle
  (`peut_encaisser_especes`, option Espèces désactivée + message dans `PaymentCard`) sans jamais en
  être la seule garantie. **Historique inchangé** : les encaissements déjà enregistrés ne sont pas
  reclassés (règle « jamais de reclassement rétroactif » maintenue) ; ils se lisent avec
  `php artisan encaissements:diagnostiquer-destination` (lecture seule), qui liste les espèces
  hors caisse dédiée par agent et indique lesquels n'ont toujours pas de caisse.
- **Points ouverts pour la suite** : sens inverse (fonds de caisse agence → agent), désactivation
  d'un utilisateur qui détient une caisse (non traitée), absence de contrôle de solde sur les
  mouvements entre agences, encaissements enregistrés par un utilisateur qui n'est pas l'agent (ils
  suivent l'auteur de l'encaissement, `created_by`, jamais le vendeur de la commande).

## Références

- Code : `App\Services\Tresorerie\CaisseAgentService`, `App\Services\Tresorerie\CaisseAgentResolver`,
  `App\Services\Tresorerie\MouvementFondsService`, `App\Models\CompteTresorerie`,
  `App\Models\MouvementFonds`, `App\Policies\CompteTresoreriePolicy`,
  `App\Services\Tresorerie\TresorerieDisponibiliteService`,
  `App\Services\Comptabilite\VenteComptabilisationService`,
  `App\Http\Controllers\Comptabilite\CompteTresorerieController`,
  `App\Http\Controllers\Comptabilite\VerserCaisseAgentController`,
  `App\Services\Tresorerie\EncaissementDestinationDiagnostic` (commande
  `encaissements:diagnostiquer-destination`).
- Tests : `CaisseAgentServiceTest`, `CompteTresorerieCaisseDedieeControllerTest`,
  `CaisseAgentImpactTresorerieTest`, `CaisseAgentEncaissementTest`,
  `VersementCaisseAgentServiceTest`, `VersementCaisseAgentControllerTest`,
  `FinancementAgenceServiceTest`, `EncaissementEspecesCaisseObligatoireTest`,
  `EncaissementsDiagnostiquerDestinationCommandTest`.
- Documentation : `docs/data-dictionary-compta.md` (« Caisses dédiées à un agent »),
  `docs/encaissements.md` (« Comptabilisation : caisse dédiée de l'agent »).
