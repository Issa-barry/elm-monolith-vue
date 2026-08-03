<?php

namespace App\Services;

use App\Enums\ModeTarification;
use App\Enums\ProduitStatut;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use App\Models\Produit;
use App\Models\ProduitStock;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdvCheckoutService
{
    /**
     * Enregistre une vente PDV directement en EN_COURS avec facture.
     * Stock du site décrémenté de manière atomique (lockForUpdate() sur
     * ProduitStock) via MouvementStockService::sortirStock().
     */
    public function checkout(array $data, User $user, string|int $siteId): CommandeVente
    {
        $this->validateMode($data);

        if ($data['mode'] === 'Livreur' && ! empty($data['vehicule_id'])) {
            $this->validateCapacite($data);
        }

        return DB::transaction(function () use ($data, $user, $siteId) {
            $mode = $this->resolveModeTarification($data['vehicule_id'] ?? null);
            [$lignesData, $total, $stockTrackedProduitIds] = $this->buildLignes($data['lignes'], $user->organization_id, (string) $siteId, $mode);

            $commande = CommandeVente::create([
                'organization_id' => $user->organization_id,
                'site_id' => $siteId,
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'total_commande' => $total,
                'mode_tarification_snapshot' => $mode->value,
                'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
                'validated_at' => now(),
                'created_by' => $user->id,
            ]);

            foreach ($lignesData as $ligne) {
                /** @var CommandeVenteLigne $ligneModel */
                $ligneModel = $commande->lignes()->create($ligne);

                if (! in_array($ligneModel->produit_id, $stockTrackedProduitIds, true)) {
                    continue;
                }

                MouvementStockService::sortirStock(
                    produitId: $ligneModel->produit_id,
                    siteId: (string) $siteId,
                    orgId: $user->organization_id,
                    quantite: $ligneModel->quantite_demandee,
                    sourceType: CommandeVenteLigne::class,
                    sourceId: $ligneModel->id,
                    userId: $user->id,
                );
            }

            FactureVente::create([
                'organization_id' => $user->organization_id,
                'site_id' => $siteId,
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'commande_vente_id' => $commande->id,
                'montant_brut' => $total,
                'montant_net' => $total,
            ]);

            return $commande;
        });
    }

    private function validateMode(array $data): void
    {
        if ($data['mode'] === 'Client' && empty($data['client_id'])) {
            throw ValidationException::withMessages([
                'client_id' => 'Un client est obligatoire pour ce mode de vente.',
            ]);
        }

        if ($data['mode'] === 'Livreur' && empty($data['vehicule_id'])) {
            throw ValidationException::withMessages([
                'vehicule_id' => 'Un véhicule est obligatoire pour ce mode de vente.',
            ]);
        }
    }

    private function resolveModeTarification(?string $vehiculeId): ModeTarification
    {
        if (! $vehiculeId) {
            return ModeTarification::PRIX_VENTE;
        }

        $vehicule = Vehicule::query()->select(['id', 'pris_en_charge_par_usine'])->find($vehiculeId);

        return ModeTarification::fromPrisEnChargeParUsine($vehicule?->pris_en_charge_par_usine ?? true);
    }

    private function validateCapacite(array $data): void
    {
        $vehicule = Vehicule::select(['id', 'capacite_packs', 'type_vehicule_id'])
            ->with('typeVehicule')
            ->find($data['vehicule_id']);

        // Import flotte ne renseigne jamais capacite_packs sur le véhicule :
        // on retombe sur la capacité par défaut du type (cf. VehiculeController).
        $capaciteVehicule = $vehicule?->capacite_packs ?? $vehicule?->typeVehicule?->capacite_defaut;

        if (! $vehicule || $capaciteVehicule === null) {
            return;
        }

        $qteTotale = collect($data['lignes'])->sum(fn ($l) => (int) ($l['quantite'] ?? 0));
        $capacite = (int) $capaciteVehicule;

        if ($qteTotale > $capacite) {
            throw ValidationException::withMessages([
                'lignes' => "La quantité totale ({$qteTotale}) dépasse la capacité du véhicule ({$capacite} packs maximum).",
            ]);
        }
    }

    /**
     * Vérifie le stock du site et construit les lignes (le décrément proprement
     * dit a lieu dans checkout(), après création de chaque ligne, via
     * MouvementStockService::sortirStock() — il lui faut l'id de la ligne pour
     * tracer le mouvement). lockForUpdate() sur les stocks du site garantit
     * l'atomicité contre les ventes concurrentes sur ce même site.
     */
    private function buildLignes(array $lignes, string|int $orgId, string $siteId, ModeTarification $mode): array
    {
        $produitIds = collect($lignes)->pluck('produit_id')->unique()->values()->all();

        $produits = Produit::whereIn('id', $produitIds)
            ->where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->get()
            ->keyBy('id');

        // Verrouille les lignes de stock du site concernées, pour empêcher une
        // survente en cas de ventes PDV concurrentes sur ce même site.
        $stocksSite = ProduitStock::where('site_id', $siteId)
            ->whereIn('produit_id', $produitIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('produit_id');

        $lignesData = [];
        $stockTrackedProduitIds = [];
        $total = 0;

        foreach ($lignes as $ligne) {
            $produitId = $ligne['produit_id'];
            $qte = (int) $ligne['quantite'];
            $produit = $produits->get($produitId);

            if (! $produit) {
                throw ValidationException::withMessages([
                    'lignes' => 'Le produit sélectionné est introuvable ou inactif.',
                ]);
            }

            if ($produit->type->hasStock()) {
                $stockTrackedProduitIds[] = $produitId;
                $disponible = $stocksSite->get($produitId)?->qte_stock;

                // Aucune ligne ProduitStock pour ce produit, sur aucun site :
                // stock jamais ventilé — on retombe sur l'agrégat legacy
                // (même convention que MouvementStockService::sortirStock()).
                if ($disponible === null) {
                    $disponible = ProduitStock::where('produit_id', $produitId)->exists()
                        ? 0
                        : (int) $produit->qte_stock;
                }

                if ($disponible < $qte) {
                    throw ValidationException::withMessages([
                        'lignes' => "Stock insuffisant pour « {$produit->nom} » sur ce site (disponible : {$disponible}, demandé : {$qte}).",
                    ]);
                }
            }

            $prixVente = (int) $produit->prix_vente;
            $prixUsine = (int) $produit->prix_usine;
            $totalLigne = $qte * ($mode === ModeTarification::PRIX_VENTE ? $prixVente : $prixUsine);

            $lignesData[] = [
                'produit_id' => $produit->id,
                'quantite_demandee' => $qte,
                'prix_usine_snapshot' => $prixUsine,
                'prix_vente_snapshot' => $prixVente,
                'total_ligne' => $totalLigne,
            ];

            $total += $totalLigne;
        }

        return [$lignesData, $total, $stockTrackedProduitIds];
    }
}
