# Capacités de chargement des véhicules

Décisions : 17/08/2026 (capacité par catégorie, portée par le véhicule), 30/10/2026 — capacité
obligatoire ([ADR 0042](adr/0042-capacite-vehicule-obligatoire-par-categorie.md)).

## Modèle

**Produit → Catégorie → Capacité véhicule.** La capacité maximale d'un véhicule se configure par
catégorie du catalogue produit (`vehicule_capacites` : véhicule, catégorie, `capacite_max`, une
ligne par couple). Le type de véhicule ne porte aucune capacité.

Saisie : fiche véhicule → Modifier → « Capacités maximales de chargement », import flotte, import de
mise à jour des véhicules (colonnes `capacite__<REFERENCE>`).

## Règles de contrôle

Moteur unique : `App\Services\VehiculeCapaciteService::verifier()`, côté serveur, sans exception de
rôle.

| Situation pour une catégorie vendue | Vente (web, précommande, PDV) | Transfert logistique |
|---|---|---|
| Plafond configuré, quantité ≤ plafond | Acceptée (*) | Accepté |
| Plafond configuré, quantité > plafond | Refusée | Refusé |
| Pas de plafond, catégorie **sans** « capacité obligatoire » | Acceptée (illimité) | Accepté |
| Pas de plafond, catégorie **avec** « capacité obligatoire » | **Refusée** | Accepté |
| Produit sans catégorie | Acceptée | Accepté |

(*) Vente web : sauf chargement incomplet quand le paramètre « Autoriser la saisie d'une quantité
inférieure à la capacité du véhicule » est désactivé. Le PDV et la logistique n'exigent jamais un
chargement complet.

- Les catégories sont **indépendantes** : chaque catégorie est contrôlée sur la somme de ses
  propres lignes ; un véhicule plafonné à 600 bouteilles et 270 sachets peut partir avec les deux.
- Contrôlé à la création et à la modification d'une commande, et au changement de mode de remise
  d'une précommande. Les commandes existantes ne sont jamais modifiées par un changement de
  capacité ou de réglage.

## Capacité obligatoire (ADR 0042)

Produits → Catégories → Modifier → **« Capacité véhicule obligatoire pour vendre »**
(`categories.capacite_vehicule_obligatoire`, faux par défaut).

Quand il est coché, un véhicule ne peut vendre les produits de la catégorie que s'il a une capacité
configurée pour elle. Message serveur :

> Le véhicule « Lansana » n'a pas de capacité maximale configurée pour « Sachet eau » : cette
> catégorie l'exige pour vendre. Renseignez-la dans la fiche du véhicule (Modifier → Capacités
> maximales de chargement).

Écrans :

- **Catégories** : mention « Capacité véhicule obligatoire » et nombre de véhicules actifs pouvant
  vendre (usage Vente, Logistique ou Grossiste) sans capacité pour la catégorie ; à l'activation, la
  fenêtre annonce combien de véhicules seront bloqués.
- **Fiche véhicule** : « <catégorie> : à renseigner — obligatoire pour vendre cette catégorie »
  (orange : le véhicule reste utilisable pour les autres catégories).
- **Éditeur de capacités** : rappel des catégories obligatoires sans ligne. L'enregistrement du
  véhicule n'est pas bloqué.
- **Formulaire de vente** (création, modification) : message rouge par catégorie vendue sans
  capacité, bouton de validation désactivé. Source : `capacites_manquantes` de chaque véhicule
  (`VehiculeCapaciteService::capacitesObligatoiresManquantes()`), même règle que le serveur.

Mise en service conseillée : renseigner la capacité des véhicules (fiche ou import de mise à jour)
puis cocher la catégorie ; le compteur de l'écran Catégories doit être à 0.

## Fichiers clés

- `app/Services/VehiculeCapaciteService.php`
- `app/Support/Ventes/CommandeVenteFormBuilder.php` (`ensureQuantiteMatchesVehiculeCapacity`,
  `mapVehiculeOption`), `app/Services/PdvCheckoutService.php`,
  `app/Http/Controllers/TransfertLogistiqueController.php`
- `app/Http/Controllers/Produits/Categories/*`, `resources/js/pages/Produits/Categories/Index.vue`
- `resources/js/pages/Vehicules/partials/CapacitesEditor.vue`, `resources/js/pages/Vehicules/Show.vue`
- `resources/js/pages/Ventes/Create.vue`, `resources/js/pages/Ventes/Edit.vue`
- Tests : `tests/Feature/VehiculeCapaciteTest.php`, `tests/Feature/CategorieTest.php`
