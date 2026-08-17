<?php

namespace App\Services;

use App\Models\Categorie;
use App\Models\Produit;
use App\Models\Vehicule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Centralise le contrôle "quantité vendue ≤ capacité du véhicule", utilisé par la vente web
 * (CommandeVenteController), le PDV (PdvCheckoutService) et les transferts logistiques
 * (TransfertLogistiqueController).
 *
 * La capacité est portée EXCLUSIVEMENT par le véhicule lui-même (vehicule_capacites), par
 * catégorie du catalogue produit (Categorie — ex: "Sachet eau", "Bouteille") — décision produit
 * du 17/08/2026 : il n'existe plus de notion intermédiaire de "groupe de capacité", la
 * catégorie du produit EST directement la référence de capacité. Une seule source de vérité
 * Produit → Catégorie → Capacité véhicule.
 *
 * Décision produit du 17/08/2026 : plus aucun héritage/repli depuis TypeVehicule — le type d'un
 * véhicule est une classification pure, changer le type d'un véhicule ne modifie jamais ses
 * capacités. Un véhicule sans aucune ligne de capacité configurée n'est simplement pas limité.
 *
 * Les plafonds des différentes catégories sont indépendants (cumulables), pas partagés — un
 * véhicule à 80 sachets + 160 bouteilles peut partir chargé des deux à la fois.
 *
 * Un produit sans catégorie n'est concerné par aucun contrôle (ne peut pas être rattaché à un
 * compteur de capacité) — l'absence de configuration n'équivaut jamais à une capacité nulle.
 *
 * Contrôle strict, sans exception de rôle : la capacité d'un véhicule ne peut jamais être
 * dépassée, quel que soit l'utilisateur.
 */
class VehiculeCapaciteService
{
    /**
     * Capacité par catégorie pour ce véhicule précis. Vide si aucune ligne n'est configurée —
     * dans ce cas le véhicule n'est simplement pas limité.
     *
     * @return Collection<string, int> categorie_id => capacite_max
     */
    public function capacitesParCategorie(Vehicule $vehicule): Collection
    {
        $vehicule->loadMissing('capacites');

        return $vehicule->capacites->pluck('capacite_max', 'categorie_id');
    }

    /**
     * @return array<int, array{categorie_id: string, categorie_nom: string, capacite_max: int}>
     */
    public function capacitesParCategorieAvecNoms(Vehicule $vehicule): array
    {
        $vehicule->loadMissing('capacites.categorie');

        return $vehicule->capacites
            ->map(fn ($c) => [
                'categorie_id' => $c->categorie_id,
                'categorie_nom' => $c->categorie?->nom ?? 'Catégorie',
                'capacite_max' => $c->capacite_max,
            ])
            ->values()
            ->all();
    }

    /**
     * Remplace intégralement les lignes de capacité d'un véhicule.
     *
     * @param  array<int, array{categorie_id: string, capacite_max: int}>  $lignes
     */
    public function syncCapacites(Vehicule $vehicule, array $lignes, string $orgId): void
    {
        DB::transaction(function () use ($vehicule, $lignes, $orgId) {
            $vehicule->capacites()->delete();
            foreach ($lignes as $ligne) {
                $vehicule->capacites()->create([
                    'organization_id' => $orgId,
                    'categorie_id' => $ligne['categorie_id'],
                    'capacite_max' => $ligne['capacite_max'],
                ]);
            }
        });
    }

    /**
     * Vérifie que les lignes vendues/chargées respectent la capacité du véhicule choisi.
     * $lignes est le tableau brut de la requête ([['produit_id' => ..., $qteKey => ...], ...]) —
     * $qteKey vaut 'qte' côté vente web, 'quantite' côté PDV, 'quantite_demandee' côté
     * logistique, seule différence entre les appelants.
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
    ): void {
        $capacitesParCategorie = $this->capacitesParCategorie($vehicule);

        if ($capacitesParCategorie->isEmpty()) {
            return;
        }

        $produitIds = collect($lignes)->pluck('produit_id')->filter()->unique()->values()->all();
        $categorieParProduit = Produit::whereIn('id', $produitIds)->pluck('categorie_id', 'id');

        $qteParCategorie = [];
        foreach ($lignes as $ligne) {
            $categorieId = $categorieParProduit[$ligne['produit_id'] ?? null] ?? null;
            // Produit sans catégorie : pas concerné par un contrôle qui, par définition, se
            // déclenche par catégorie.
            if ($categorieId === null) {
                continue;
            }
            $qteParCategorie[$categorieId] = ($qteParCategorie[$categorieId] ?? 0) + (int) ($ligne[$qteKey] ?? 0);
        }

        foreach ($qteParCategorie as $categorieId => $qte) {
            $max = $capacitesParCategorie->get($categorieId);
            // Catégorie vendue mais sans plafond configuré pour ce véhicule : illimité.
            if ($max === null) {
                continue;
            }

            $nom = Categorie::find($categorieId)?->nom ?? 'catégorie';

            if ($qte > $max) {
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
}
