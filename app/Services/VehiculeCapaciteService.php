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
 * (CommandeVenteFormBuilder), le PDV (PdvCheckoutService) et les transferts logistiques
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
 *
 * Capacité obligatoire (ADR 0042) : une catégorie marquée `capacite_vehicule_obligatoire` ne peut
 * être VENDUE avec un véhicule que si ce véhicule a une capacité configurée pour elle — l'absence
 * de plafond n'y vaut plus « illimité » mais « vente refusée ». Les transferts logistiques ne sont
 * pas concernés (l'appelant décide via `$exigerCapaciteObligatoire`).
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
        bool $exigerCapaciteObligatoire = false,
    ): void {
        $capacitesParCategorie = $this->capacitesParCategorie($vehicule);

        if ($capacitesParCategorie->isEmpty() && ! $exigerCapaciteObligatoire) {
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

        if ($exigerCapaciteObligatoire) {
            $this->verifierCapacitesObligatoires($vehicule, $capacitesParCategorie, $qteParCategorie);
        }

        foreach ($qteParCategorie as $categorieId => $qte) {
            $max = $capacitesParCategorie->get($categorieId);
            // Catégorie vendue sans plafond configuré pour ce véhicule (et non obligatoire) :
            // illimité.
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

    /**
     * Catégories exigeant une capacité (ADR 0042) pour lesquelles CE véhicule n'en a aucune —
     * ce véhicule ne peut pas vendre leurs produits tant qu'elles ne sont pas configurées.
     *
     * @return array<int, array{categorie_id: string, categorie_nom: string}>
     */
    public function capacitesObligatoiresManquantes(Vehicule $vehicule): array
    {
        $configurees = $this->capacitesParCategorie($vehicule)->keys()->all();

        return $this->categoriesObligatoires($vehicule->organization_id)
            ->reject(fn (Categorie $c) => in_array($c->id, $configurees, true))
            ->map(fn (Categorie $c) => ['categorie_id' => $c->id, 'categorie_nom' => $c->nom])
            ->values()
            ->all();
    }

    /** @var array<string, Collection<int, Categorie>> */
    private array $categoriesObligatoiresParOrg = [];

    /** @return Collection<int, Categorie> */
    private function categoriesObligatoires(string $orgId): Collection
    {
        return $this->categoriesObligatoiresParOrg[$orgId] ??= Categorie::query()
            ->where('organization_id', $orgId)
            ->where('capacite_vehicule_obligatoire', true)
            ->orderBy('nom')
            ->get(['id', 'nom']);
    }

    /**
     * @param  Collection<string, int>  $capacitesParCategorie
     * @param  array<string, int>  $qteParCategorie
     *
     * @throws ValidationException
     */
    private function verifierCapacitesObligatoires(Vehicule $vehicule, Collection $capacitesParCategorie, array $qteParCategorie): void
    {
        $vendues = array_keys(array_filter($qteParCategorie, fn (int $qte) => $qte > 0));
        if ($vendues === []) {
            return;
        }

        $manquantes = Categorie::query()
            ->whereIn('id', $vendues)
            ->where('capacite_vehicule_obligatoire', true)
            ->orderBy('nom')
            ->get(['id', 'nom'])
            ->reject(fn (Categorie $c) => $capacitesParCategorie->has($c->id));

        if ($manquantes->isEmpty()) {
            return;
        }

        $noms = $manquantes->map(fn (Categorie $c) => "« {$c->nom} »")->implode(', ');
        $pluriel = $manquantes->count() > 1;

        throw ValidationException::withMessages([
            'lignes' => "Le véhicule « {$vehicule->nom_vehicule} » n'a pas de capacité maximale configurée pour {$noms}"
                .($pluriel ? " : ces catégories l'exigent" : " : cette catégorie l'exige")
                .' pour vendre. Renseignez-la dans la fiche du véhicule (Modifier → Capacités maximales de chargement).',
        ]);
    }
}
