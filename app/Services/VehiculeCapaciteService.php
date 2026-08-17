<?php

namespace App\Services;

use App\Models\GroupeCapacite;
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
 * groupe de capacité (GroupeCapacite — ex: "Sachets", "Bouteilles"), délibérément distinct de
 * la Categorie du catalogue produit (Produits finis, Matières premières...) : deux organisations
 * peuvent classer leur catalogue très différemment de leurs contraintes réelles de chargement.
 *
 * Décision produit du 17/08/2026 : plus aucun héritage/repli depuis TypeVehicule — le type d'un
 * véhicule est une classification pure, changer le type d'un véhicule ne modifie jamais ses
 * capacités. Un véhicule sans aucune ligne de capacité configurée n'est simplement pas limité
 * (comme un groupe vendu sans plafond configuré pour ce véhicule précis).
 *
 * Les plafonds des différents groupes sont indépendants (cumulables), pas partagés — un véhicule
 * à 1700 sachets + 3400 bouteilles peut partir chargé des deux à la fois.
 *
 * Contrôle strict, sans exception de rôle : la capacité d'un véhicule ne peut jamais être
 * dépassée, quel que soit l'utilisateur.
 */
class VehiculeCapaciteService
{
    /**
     * Capacité par groupe pour ce véhicule précis. Vide si aucune ligne n'est configurée — dans
     * ce cas le véhicule n'est simplement pas limité.
     *
     * @return Collection<string, int> groupe_capacite_id => capacite_max
     */
    public function capacitesParGroupe(Vehicule $vehicule): Collection
    {
        $vehicule->loadMissing('capacites');

        return $vehicule->capacites->pluck('capacite_max', 'groupe_capacite_id');
    }

    /**
     * @return array<int, array{groupe_capacite_id: string, groupe_capacite_nom: string, capacite_max: int}>
     */
    public function capacitesParGroupeAvecNoms(Vehicule $vehicule): array
    {
        $vehicule->loadMissing('capacites.groupeCapacite');

        return $vehicule->capacites
            ->map(fn ($c) => [
                'groupe_capacite_id' => $c->groupe_capacite_id,
                'groupe_capacite_nom' => $c->groupeCapacite?->nom ?? 'Groupe',
                'capacite_max' => $c->capacite_max,
            ])
            ->values()
            ->all();
    }

    /**
     * Remplace intégralement les lignes de capacité d'un véhicule.
     *
     * @param  array<int, array{groupe_capacite_id: string, capacite_max: int}>  $lignes
     */
    public function syncCapacites(Vehicule $vehicule, array $lignes, string $orgId): void
    {
        DB::transaction(function () use ($vehicule, $lignes, $orgId) {
            $vehicule->capacites()->delete();
            foreach ($lignes as $ligne) {
                $vehicule->capacites()->create([
                    'organization_id' => $orgId,
                    'groupe_capacite_id' => $ligne['groupe_capacite_id'],
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
        $capacitesParGroupe = $this->capacitesParGroupe($vehicule);

        if ($capacitesParGroupe->isEmpty()) {
            return;
        }

        $produitIds = collect($lignes)->pluck('produit_id')->filter()->unique()->values()->all();
        $groupeParProduit = Produit::whereIn('id', $produitIds)->pluck('groupe_capacite_id', 'id');

        $qteParGroupe = [];
        foreach ($lignes as $ligne) {
            $groupeId = $groupeParProduit[$ligne['produit_id'] ?? null] ?? null;
            // Produit sans groupe de capacité : pas concerné par un contrôle qui, par
            // définition, se déclenche par groupe.
            if ($groupeId === null) {
                continue;
            }
            $qteParGroupe[$groupeId] = ($qteParGroupe[$groupeId] ?? 0) + (int) ($ligne[$qteKey] ?? 0);
        }

        foreach ($qteParGroupe as $groupeId => $qte) {
            $max = $capacitesParGroupe->get($groupeId);
            // Groupe vendu mais sans plafond configuré pour ce véhicule : illimité.
            if ($max === null) {
                continue;
            }

            $nom = GroupeCapacite::find($groupeId)?->nom ?? 'groupe de capacité';

            if ($qte > $max) {
                throw ValidationException::withMessages([
                    'lignes' => "La quantité « {$nom} » ({$qte}) dépasse la capacité du véhicule pour ce groupe ({$max} maximum).",
                ]);
            }

            if ($exigerChargementComplet && $qte < $max) {
                throw ValidationException::withMessages([
                    'lignes' => "La quantité « {$nom} » ({$qte}) est inférieure à la capacité du véhicule pour ce groupe ({$max}). Le chargement complet est obligatoire.",
                ]);
            }
        }
    }
}
