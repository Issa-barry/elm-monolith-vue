<?php

namespace App\Services;

use App\Enums\ProduitStatut;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdvCheckoutService
{
    /**
     * Enregistre une vente PDV directement en EN_COURS avec facture.
     * Stock décrémenté de manière atomique via lockForUpdate().
     */
    public function checkout(array $data, User $user, string|int $siteId): CommandeVente
    {
        $this->validateMode($data);

        if ($data['mode'] === 'Livreur' && ! empty($data['vehicule_id'])) {
            $this->validateCapacite($data);
        }

        return DB::transaction(function () use ($data, $user, $siteId) {
            [$lignesData, $total] = $this->buildLignes($data['lignes'], $user->organization_id);

            $commande = CommandeVente::create([
                'organization_id' => $user->organization_id,
                'site_id' => $siteId,
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'total_commande' => $total,
                'statut' => StatutCommandeVente::EN_COURS,
                'validated_at' => now(),
                'created_by' => $user->id,
            ]);

            foreach ($lignesData as $ligne) {
                $commande->lignes()->create($ligne);
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

    private function validateCapacite(array $data): void
    {
        $vehicule = Vehicule::select(['id', 'capacite_packs'])->find($data['vehicule_id']);

        if (! $vehicule || $vehicule->capacite_packs === null) {
            return;
        }

        $qteTotale = collect($data['lignes'])->sum(fn ($l) => (int) ($l['quantite'] ?? 0));
        $capacite = (int) $vehicule->capacite_packs;

        if ($qteTotale > $capacite) {
            throw ValidationException::withMessages([
                'lignes' => "La quantité totale ({$qteTotale}) dépasse la capacité du véhicule ({$capacite} packs maximum).",
            ]);
        }
    }

    /**
     * Vérifie le stock, décrémente atomiquement et construit les lignes.
     * lockForUpdate() garantit l'atomicité contre les ventes concurrentes.
     */
    private function buildLignes(array $lignes, string|int $orgId): array
    {
        $produitIds = collect($lignes)->pluck('produit_id')->unique()->values()->all();

        $produits = Produit::lockForUpdate()
            ->whereIn('id', $produitIds)
            ->where('organization_id', $orgId)
            ->where('statut', ProduitStatut::ACTIF)
            ->get()
            ->keyBy('id');

        $lignesData = [];
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

            if ($produit->type->hasStock() && $produit->qte_stock < $qte) {
                throw ValidationException::withMessages([
                    'lignes' => "Stock insuffisant pour « {$produit->nom} » (disponible : {$produit->qte_stock}, demandé : {$qte}).",
                ]);
            }

            if ($produit->type->hasStock()) {
                $produit->decrement('qte_stock', $qte);
            }

            $prixVente = (int) $produit->prix_vente;
            $totalLigne = $qte * $prixVente;

            $lignesData[] = [
                'produit_id' => $produit->id,
                'qte' => $qte,
                'prix_usine_snapshot' => (int) $produit->prix_usine,
                'prix_vente_snapshot' => $prixVente,
                'total_ligne' => $totalLigne,
            ];

            $total += $totalLigne;
        }

        return [$lignesData, $total];
    }
}
