<?php

namespace App\Services;

use App\Models\Categorie;
use App\Models\Produit;
use App\Models\Vehicule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Centralise le contrôle "quantité vendue ≤ capacité du véhicule", utilisé par la vente web
 * (CommandeVenteController) et le PDV (PdvCheckoutService) — avant cette classe, chacun avait
 * sa propre copie du même calcul.
 *
 * Deux régimes, choisis automatiquement par véhicule :
 * - Aucune ligne dans vehicule_capacites/type_vehicule_capacites pour ce véhicule (ou son
 *   type) → régime "legacy" : un seul plafond global (vehicules.capacite_packs, ou à défaut
 *   type_vehicules.capacite_defaut), toutes catégories de produit confondues. C'est le
 *   comportement historique, préservé tel quel pour ne rien casser sur la flotte existante.
 * - Au moins une ligne définie → régime "par catégorie" : chaque catégorie de produit vendue
 *   est comparée à SON propre plafond (capacité du véhicule pour cette catégorie, sinon celle
 *   par défaut du type). Une catégorie vendue sans plafond défini n'est pas limitée. Les
 *   plafonds des différentes catégories sont indépendants (cumulables), pas partagés — décision
 *   produit du 10/08/2026 : un véhicule à 70 sachets + 100 bouteilles peut partir chargé des
 *   deux à la fois.
 */
class VehiculeCapaciteService
{
    /**
     * Capacité effective par catégorie pour ce véhicule (capacité propre si définie, sinon
     * celle par défaut de son type). Vide si aucune ligne n'a été configurée nulle part —
     * dans ce cas l'appelant doit basculer sur le régime legacy.
     *
     * @return Collection<string, int> categorie_id => capacite_max
     */
    public function capacitesParCategorie(Vehicule $vehicule): Collection
    {
        $vehicule->loadMissing(['capacites', 'typeVehicule.capacites']);

        $capacites = collect();
        foreach ($vehicule->typeVehicule?->capacites ?? [] as $c) {
            $capacites->put($c->categorie_id, $c->capacite_max);
        }
        foreach ($vehicule->capacites as $c) {
            $capacites->put($c->categorie_id, $c->capacite_max);
        }

        return $capacites;
    }

    /**
     * Capacité globale legacy (toutes catégories confondues) — même repli que l'historique
     * capacite_packs ?? typeVehicule.capacite_defaut.
     */
    public function capaciteGlobaleLegacy(Vehicule $vehicule): ?int
    {
        $vehicule->loadMissing('typeVehicule');
        $capacite = $vehicule->capacite_packs ?? $vehicule->typeVehicule?->capacite_defaut;

        return $capacite !== null ? (int) $capacite : null;
    }

    /**
     * Vérifie que les lignes vendues respectent la capacité du véhicule choisi. $lignes est le
     * tableau brut de la requête ([['produit_id' => ..., $qteKey => ...], ...]) — $qteKey vaut
     * 'qte' côté vente web, 'quantite' côté PDV, seule différence entre les deux appelants.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     *
     * @throws ValidationException
     */
    public function verifier(
        Vehicule $vehicule,
        array $lignes,
        string $qteKey,
        bool $exigerChargementComplet,
        bool $peutDepasserCapacite,
    ): void {
        $capacitesParCategorie = $this->capacitesParCategorie($vehicule);

        if ($capacitesParCategorie->isEmpty()) {
            $this->verifierGlobalLegacy($vehicule, $lignes, $qteKey, $exigerChargementComplet, $peutDepasserCapacite);

            return;
        }

        $produitIds = collect($lignes)->pluck('produit_id')->filter()->unique()->values()->all();
        $categorieParProduit = Produit::whereIn('id', $produitIds)->pluck('categorie_id', 'id');

        $qteParCategorie = [];
        foreach ($lignes as $ligne) {
            $categorieId = $categorieParProduit[$ligne['produit_id'] ?? null] ?? null;
            // Produit sans catégorie : pas concerné par un contrôle qui, par définition,
            // se déclenche par catégorie — voir le régime legacy pour un plafond global.
            if ($categorieId === null) {
                continue;
            }
            $qteParCategorie[$categorieId] = ($qteParCategorie[$categorieId] ?? 0) + (int) ($ligne[$qteKey] ?? 0);
        }

        foreach ($qteParCategorie as $categorieId => $qte) {
            $max = $capacitesParCategorie->get($categorieId);
            // Catégorie vendue mais sans plafond configuré pour ce véhicule : illimitée.
            if ($max === null) {
                continue;
            }

            $nom = Categorie::find($categorieId)?->nom ?? 'catégorie';

            if ($qte > $max && ! $peutDepasserCapacite) {
                throw ValidationException::withMessages([
                    'lignes' => "La quantité « {$nom} » ({$qte}) dépasse la capacité du véhicule pour cette catégorie ({$max} maximum).",
                ]);
            }

            if ($exigerChargementComplet && $qte < $max) {
                throw ValidationException::withMessages([
                    'lignes' => "La quantité « {$nom} » ({$qte}) est inférieure à la capacité du véhicule pour cette catégorie ({$max}). Le chargement complet est obligatoire.",
                ]);
            }
        }
    }

    private function verifierGlobalLegacy(
        Vehicule $vehicule,
        array $lignes,
        string $qteKey,
        bool $exigerChargementComplet,
        bool $peutDepasserCapacite,
    ): void {
        $capacite = $this->capaciteGlobaleLegacy($vehicule);

        if ($capacite === null) {
            throw ValidationException::withMessages([
                'vehicule_id' => 'Le véhicule sélectionné n\'a pas de capacité définie.',
            ]);
        }

        $qteTotale = collect($lignes)->sum(fn (array $ligne): int => (int) ($ligne[$qteKey] ?? 0));

        if ($qteTotale > $capacite && ! $peutDepasserCapacite) {
            throw ValidationException::withMessages([
                'lignes' => "La quantité totale ({$qteTotale} packs) dépasse la capacité du véhicule ({$capacite} packs maximum).",
            ]);
        }

        if ($exigerChargementComplet && $qteTotale < $capacite) {
            throw ValidationException::withMessages([
                'lignes' => "La quantité totale ({$qteTotale} packs) est inférieure à la capacité du véhicule ({$capacite} packs). Le chargement complet est obligatoire.",
            ]);
        }
    }
}
