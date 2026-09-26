# Références métier — préfixe par processus (révisé le 31/08/2026)

## Contexte

Avant ce chantier, `CommandeVente` (vente standard **et** distribution client confondues)
utilisait un unique préfixe `CMD-`, et `TransfertLogistique` un préfixe `TR-` distinct mais
généré sans verrou (course possible sous créations concurrentes). Devenu ambigu une fois Vente,
Distribution client et Transfert logistique traités comme trois processus métier distincts
(cf. `docs/commissions.md`), le préfixe identifie désormais directement l'origine d'une
référence — sans avoir à consulter une autre colonne (nature_operation, type de source...).

## Format

```
PREFIXE-JJMMAA-NNN
```

| Origine | Préfixe | Exemple |
|---|---|---|
| `CommandeVente`, `nature_operation = vente_standard` | `VTE` | `VTE-310826-001` |
| `CommandeVente`, `nature_operation = distribution_client` | `DST` | `DST-310826-001` |
| `TransfertLogistique` | `TRF` | `TRF-310826-001` |

## Génération — `App\Services\ReferenceNumeroService`

- Compteur **journalier** (repart à 001 chaque jour — cohérent avec `JJMMAA` affiché dans la
  référence) et **scopé par organisation** (`organization_id + prefixe + periode`) : deux
  organisations ne partagent jamais un compteur, deux processus non plus, même le même jour pour
  la même organisation.
- Table `reference_sequences` (clé composite `organization_id + prefixe + periode`), verrouillée
  en écriture (`SELECT ... FOR UPDATE` dans une transaction) — même principe que
  `App\Services\Comptabilite\PieceNumerotationService`. Limite de 999 références par
  organisation/préfixe/jour (`\OverflowException` au-delà).
- `NatureOperation::prefixeReference()` est l'unique source de vérité du préfixe pour
  `CommandeVente` — jamais dupliqué ailleurs. `TransfertLogistique` utilise la constante privée
  `TransfertLogistique::REFERENCE_PREFIX`.
- Généré dans `CommandeVente::booted()::creating` et `TransfertLogistique::booted()::creating` —
  les deux points de création runtime de chaque modèle (back-office + PDV pour `CommandeVente` ;
  back-office web + API pour `TransfertLogistique`) passent par le même code, aucune logique
  dupliquée par point d'entrée.

## Compatibilité ascendante — jamais de renommage

- Les références déjà émises (`CMD-...`, `TR-...`) **ne sont jamais modifiées**. L'ancienne
  table `commande_sequences` (compteur mensuel, partagé entre toutes les organisations —
  particularité historique non reproduite) reste intacte mais n'est plus jamais écrite ; elle ne
  sert que d'archive pour comprendre l'historique des `CMD-...` déjà émises.
- `App\Http\Controllers\Api\Mobile\ScanCommandeController` reconnaît explicitement les deux
  générations de préfixes (`VTE-`/`DST-`/legacy `CMD-` → commande ; `TRF-`/legacy `TR-` →
  transfert) — voir `docs/api-espace-client-contract.md`. Filtre systématiquement par
  `organization_id` de l'utilisateur authentifié : la numérotation étant désormais scopée par
  organisation, deux organisations peuvent porter *exactement* la même référence (ex:
  `VTE-310826-001` pour l'une ET l'autre) — une recherche non scopée serait donc fonctionnellement
  ambiguë (pas seulement un défaut d'isolation), avec un vrai risque de retourner les données
  d'une autre organisation.
- Recherche (`CommandeVenteSearchProvider`, recherche globale) n'a jamais fait de distinction de
  préfixe (`WHERE reference LIKE %...%` générique) et est déjà scopée par organisation
  (`SiteScopeService`/`organization_id` explicite, cf. `search()`) — aucune modification
  nécessaire, ancien et nouveau format cohabitent nativement.
- **Hors périmètre, signalé mais non corrigé** (le chantier du 31/08/2026 n'a corrigé que
  `ScanCommandeController`, sur demande explicite) : `App\Http\Controllers\ScanLivraisonController`
  (scan web USB) résout par `where('reference', ...)` **sans filtre `organization_id`**, sur les
  deux modèles — même ambiguïté désormais possible (deux organisations, même référence) que celle
  corrigée sur le scan mobile. Le risque de fuite de données est atténué par l'autorisation de la
  page de destination (`CommandeVentePolicy`/policy transfert, qui bloquent l'affichage
  cross-organisation), mais l'utilisateur peut être redirigé vers la mauvaise organisation avant
  de se voir bloqué — candidat naturel à la même correction si demandée. Toujours vrai au
  05/09/2026, non corrigé par le chantier scanner caméra ci-dessous (hors périmètre demandé).

## Scan côté backoffice (web) — famille `Scan*Controller`

Trois contrôleurs indépendants résolvent un texte/code scanné en URL de fiche
backoffice, tous sous `Route::middleware(['auth'])`, appelés en JSON
(`Accept: application/json`) par deux points d'entrée frontend qui partagent la même
logique de reconnaissance (`resources/js/composables/scan/scanResolvers.ts`) :
le scanner USB clavier (`useScanInterceptor`, actif sur tout le backoffice) et le
scanner caméra (`ScannerModal.vue`, dashboard mobile uniquement, ajouté le
05/09/2026, cf. `docs/scanner-dashboard-mobile.md`).

| Route | Contrôleur | Entrée | Garde |
|---|---|---|---|
| `GET /scan/user/{userId}` | `ScanUserController` | ULID nu (QR propriétaire/livreur de l'app mobile) | `auth` seul |
| `GET /scan/livraison/{reference}` | `ScanLivraisonController` | Référence `VT-`/`TR-` | `auth` seul — cf. gap ci-dessus |
| `GET /scan/produit/{code}` | `ScanProduitController` | Code-barres ou SKU produit | `auth` **+ `produits.read`** (`ProduitPolicy::viewAny`) |

`ScanProduitController` est le seul des trois à vérifier une permission explicite (en
plus d'être scopé par `organization_id`, ce que les deux autres ne font pas encore) :
un code-barres produit expose potentiellement des données commerciales via la fiche
produit, contrairement à une simple redirection vers une fiche déjà protégée par sa
propre policy. Recherche stricte (égalité, jamais `LIKE`) : `code_barres` d'abord
(colonne unique par organisation), puis `sku` en repli — jamais une recherche partielle
qui risquerait une correspondance ambiguë entre deux produits.

## Contrainte d'unicité — scopée par organisation (migration `scope_reference_unique_par_organisation`)

`commandes_ventes.reference`, `factures_ventes.reference` et `transferts_logistiques.reference`
portaient chacune une contrainte `unique` **globale**, héritée de l'ancien compteur partagé entre
organisations — elle interdisait mécaniquement à deux organisations de porter la même référence
le même jour, contredisant directement l'objectif de séquences indépendantes par organisation.
Remplacée par une contrainte composite `unique(organization_id, reference)` sur les trois tables :
l'unicité **par organisation** reste garantie à 100 %, celle entre organisations disparaît (elle
n'a plus de raison d'être). Migration additive et sûre sur les données existantes (toute ligne
déjà unique globalement l'est trivialement aussi par organisation).

## Ce qui n'a pas changé

- `reference` reste une colonne recherchable, jamais une clé technique — la clé primaire réelle
  des deux modèles est un ULID (`HasUlids`). Aucune relation ne dépend du format de `reference`.
- `FactureVente.reference` continue de recopier telle quelle la référence de sa `CommandeVente` —
  aucune logique de génération propre à mettre à jour (seule sa contrainte d'unicité a dû suivre
  le même correctif que `commandes_ventes`, cf. ci-dessus).
- `code_confirmation` sur `TransfertLogistique` (code affiché au chauffeur) reste généré
  indépendamment de `reference` — il n'entre simplement plus dans sa composition (l'ancien format
  `TR-NNNNN-XXX` l'y intégrait pour éviter une collision liée à l'absence de verrou ; devenu
  inutile une fois la génération correctement verrouillée).
