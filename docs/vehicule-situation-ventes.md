# Situation véhicule — onglet Ventes

Chantier du 15/09/2026 : nouvel onglet **Situation** sur la fiche véhicule
(`Vehicules/Show.vue`), limité en V1 à l'activité de vente réelle du véhicule. Aucune nouvelle
mécanique financière — uniquement de la lecture/agrégation des mécanismes existants
(`CommandeVente`/`FactureVente`/`EncaissementVente`).

## Périmètre V1

CA vendu, encaissé, reste dû, nombre de ventes, produits vendus (quantité + montant), historique
des ventes, filtre période (Tout / Mois / Année).

**Hors périmètre V1** (reporté à une itération suivante, cf. audit du 15/09/2026) :
- commissions générées/payées/restantes rattachées au véhicule ;
- dépenses et autres mouvements financiers ;
- marge, rentabilité, comparaison de périodes.

## Règles métier

### Une vente « vendue » exclut le brouillon et l'annulation

Une `CommandeVente` rattachée au véhicule (`vehicule_id`) n'entre dans le CA vendu, les
produits vendus et l'historique de l'onglet Situation que si son statut a dépassé `brouillon`
et n'est pas `annulee` (`App\Enums\StatutCommandeVente`). Une commande encore en brouillon n'a
rien vendu ; une commande annulée non plus.

Décision produit du 15/09/2026 (validée après audit) : un brouillon simplement enregistré mais
jamais réalisé ne doit jamais apparaître dans le chiffre d'affaires d'un véhicule.

Les deux natures d'opération rattachées à un véhicule (`vente_standard` et
`distribution_client`, cf. `docs/commissions.md` COMM-001) sont additionnées ensemble dans
cette V1 — les deux sont commercialement des ventes à part entière pour ce véhicule (même
`CommandeVente`/`FactureVente`, cf. COMM-004).

### CA, encaissé, reste dû

Aucun nouveau calcul financier : réutilise exactement les accesseurs existants de
`FactureVente` (`montant_encaisse`, `montant_restant`), eux-mêmes basés sur la somme des
`EncaissementVente` liés à la facture de chaque commande. Même formule que
`Ventes\IndexCommandeVenteController` (écran Ventes global), appliquée ici filtrée sur
`vehicule_id`.

### Quantité vendue

Il n'existe pas de colonne `quantite_vendue` sur `commande_vente_lignes` (seulement
`quantite_demandee`, `quantite_chargee`, `quantite_livree`). La quantité vendue affichée par
produit reprend le repère déjà établi par `CashbackService::quantiteEligible()` : **quantité
livrée si renseignée, sinon quantité demandée** (`quantite_livree ?? quantite_demandee`) — une
vente sans étape de chargement/livraison (comptoir) n'a jamais `quantite_livree` renseignée,
la quantité demandée en tient lieu. Décision explicite : ne pas créer de nouvelle colonne
`quantite_vendue` pour cette V1.

### Libellé produit

Le libellé affiché est le snapshot figé à la vente (`CommandeVenteLigne.libelle_snapshot`),
jamais le nom produit courant — cohérent avec le reste du système (le produit peut avoir été
renommé depuis).

## Implémentation

- `App\Services\Vehicules\VehiculeSituationVentesService::pourVehicule()` — seule source de
  calcul, appelée depuis `VehiculeController::show()`.
- `App\Models\Vehicule::commandesVentes()` — relation `hasMany(CommandeVente::class)` ajoutée
  (aucune relation inverse n'existait jusqu'ici, seul `CommandeVente::vehicule()` existait).
- Prop Inertia `situation_ventes` (kpis/produits/ventes) + `situation_periode` sur
  `Vehicules/Show`, chargés dans le même appel que les autres onglets (`equipe`, `depenses`) —
  pas de fetch paresseux séparé, pour rester cohérent avec le pattern déjà en place sur cette
  page. Changer la période (`situation_periode=all|month|year`) déclenche un rechargement
  Inertia `preserveState` (même pattern que le sélecteur de processus déjà présent sur cette
  page), sans perdre l'onglet actif.
- Composant `resources/js/pages/Vehicules/partials/SituationVentesTab.vue`.

## Points hors périmètre identifiés (non traités dans ce chantier)

- `resources/js/components/StatusDot.vue` (`STATUS_COLOR_MAP`) ne couvre pas la valeur de
  statut `facturation` — tombe sur la couleur grise par défaut. Gap préexistant, déjà présent
  sur l'écran Ventes (`Ventes/Index.vue`) avant ce chantier, non introduit par lui.
