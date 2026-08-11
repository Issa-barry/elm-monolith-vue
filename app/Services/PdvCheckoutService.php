<?php

namespace App\Services;

use App\Enums\ModeTarification;
use App\Enums\ProduitStatut;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdvCheckoutService
{
    public function __construct(private readonly VehiculeCapaciteService $vehiculeCapaciteService) {}

    /**
     * Enregistre une vente PDV directement en EN_COURS avec facture.
     * Stock du site décrémenté de manière atomique (lockForUpdate() sur
     * VarianteStock) via MouvementStockService::sortirStock().
     */
    public function checkout(array $data, User $user, string|int $siteId): CommandeVente
    {
        $this->validateMode($data);

        if ($data['mode'] === 'Livreur' && ! empty($data['vehicule_id'])) {
            $this->validateCapacite($data);
        }

        return DB::transaction(function () use ($data, $user, $siteId) {
            $context = VehiculeCommandeContextResolver::resolve($data['vehicule_id'] ?? null);
            [$lignesData, $total, $stockTrackedVarianteIds] = $this->buildLignes($data['lignes'], $user->organization_id, (string) $siteId, $context->modeTarification);

            $commande = CommandeVente::create([
                'organization_id' => $user->organization_id,
                'site_id' => $siteId,
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'total_commande' => $total,
                'mode_tarification_snapshot' => $context->modeTarification->value,
                'commission_eligible_snapshot' => $context->commissionEligible,
                'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
                'validated_at' => now(),
                'created_by' => $user->id,
            ]);

            foreach ($lignesData as $ligne) {
                /** @var CommandeVenteLigne $ligneModel */
                $ligneModel = $commande->lignes()->create($ligne);

                if (! in_array($ligneModel->variante_id, $stockTrackedVarianteIds, true)) {
                    continue;
                }

                MouvementStockService::sortirStock(
                    varianteId: $ligneModel->variante_id,
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

    private function validateCapacite(array $data): void
    {
        $vehicule = Vehicule::find($data['vehicule_id']);
        if (! $vehicule) {
            return;
        }

        // PDV n'a jamais exigé de chargement complet (contrairement à la vente web, cf.
        // Parametre::isVentesAutorisationSaisieDessousQteMax) ni de bypass par permission —
        // comportement historique préservé tel quel.
        $this->vehiculeCapaciteService->verifier($vehicule, $data['lignes'], 'quantite', false, false);
    }

    /**
     * Résout la variante réellement vendue. La grille PDV actuelle ne propose qu'une
     * sélection de produit (pas de sélecteur de variante — Phase 3) : si le produit n'a
     * qu'une seule variante (cas normal), on la prend directement ; sinon on exige que
     * variante_id soit explicitement fourni plutôt que de deviner laquelle vendre.
     */
    private function resolveVariante(array $ligne, string $orgId): ProduitVariante
    {
        if (! empty($ligne['variante_id'])) {
            $variante = ProduitVariante::with('produit')->find($ligne['variante_id']);

            if (! $variante || $variante->produit_id !== $ligne['produit_id']) {
                throw ValidationException::withMessages([
                    'lignes' => 'La variante sélectionnée est introuvable.',
                ]);
            }
        } else {
            $produit = Produit::with('variantes')
                ->where('organization_id', $orgId)
                ->where('statut', ProduitStatut::ACTIF)
                ->find($ligne['produit_id']);

            if (! $produit) {
                throw ValidationException::withMessages([
                    'lignes' => 'Le produit sélectionné est introuvable ou inactif.',
                ]);
            }

            if ($produit->variantes->count() === 1) {
                $variante = $produit->variantes->first();
                $variante->setRelation('produit', $produit);
            } elseif ($produit->variantes->count() > 1) {
                throw ValidationException::withMessages([
                    'lignes' => "Le produit « {$produit->nom} » a plusieurs déclinaisons — précisez la variante à vendre.",
                ]);
            } else {
                throw ValidationException::withMessages([
                    'lignes' => "Le produit « {$produit->nom} » n'a aucune variante disponible.",
                ]);
            }
        }

        if (! $variante->produit || $variante->produit->organization_id !== $orgId || $variante->produit->statut !== ProduitStatut::ACTIF) {
            throw ValidationException::withMessages([
                'lignes' => 'Le produit sélectionné est introuvable ou inactif.',
            ]);
        }

        return $variante;
    }

    /**
     * Vérifie le stock du site et construit les lignes (le décrément proprement
     * dit a lieu dans checkout(), après création de chaque ligne, via
     * MouvementStockService::sortirStock() — il lui faut l'id de la ligne pour
     * tracer le mouvement). lockForUpdate() sur les stocks du site garantit
     * l'atomicité contre les ventes concurrentes sur ce même site.
     */
    private function buildLignes(array $lignes, string $orgId, string $siteId, ModeTarification $mode): array
    {
        $resolved = collect($lignes)->map(fn (array $ligne) => [
            'variante' => $this->resolveVariante($ligne, $orgId),
            'qte' => (int) $ligne['quantite'],
        ]);

        $varianteIds = $resolved->pluck('variante.id')->unique()->values()->all();

        // Verrouille les lignes de stock du site concernées, pour empêcher une
        // survente en cas de ventes PDV concurrentes sur ce même site.
        $stocksSite = VarianteStock::where('site_id', $siteId)
            ->whereIn('produit_variante_id', $varianteIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('produit_variante_id');

        $lignesData = [];
        $stockTrackedVarianteIds = [];
        $total = 0;

        foreach ($resolved as $item) {
            $variante = $item['variante'];
            $produit = $variante->produit;
            $qte = $item['qte'];

            if ($produit->type->hasStock()) {
                $stockTrackedVarianteIds[] = $variante->id;
                $disponible = $stocksSite->get($variante->id)?->qte_stock;

                // Aucune ligne VarianteStock pour cette variante, sur aucun site :
                // stock jamais ventilé — on retombe sur l'agrégat legacy du produit parent
                // (même convention que MouvementStockService::sortirStock()).
                if ($disponible === null) {
                    $disponible = VarianteStock::where('produit_variante_id', $variante->id)->exists()
                        ? 0
                        : (int) $produit->qte_stock;
                }

                if ($disponible < $qte) {
                    throw ValidationException::withMessages([
                        'lignes' => "Stock insuffisant pour « {$produit->nom} » sur ce site (disponible : {$disponible}, demandé : {$qte}).",
                    ]);
                }
            }

            $prixVente = (int) ($variante->prix_vente ?? 0);
            $prixUsine = (int) ($variante->prix_usine ?? 0);
            $totalLigne = $qte * ($mode === ModeTarification::PRIX_VENTE ? $prixVente : $prixUsine);

            $lignesData[] = [
                'variante_id' => $variante->id,
                'quantite_demandee' => $qte,
                'prix_usine_snapshot' => $prixUsine,
                'prix_vente_snapshot' => $prixVente,
                'total_ligne' => $totalLigne,
                'libelle_snapshot' => $variante->libelle !== '' ? "{$produit->nom} — {$variante->libelle}" : $produit->nom,
            ];

            $total += $totalLigne;
        }

        return [$lignesData, $total, $stockTrackedVarianteIds];
    }
}
