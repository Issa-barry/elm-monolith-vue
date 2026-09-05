<?php

namespace App\Services;

use App\Enums\CategorieTarifaireVehicule;
use App\Enums\ClientType;
use App\Enums\ModeTarification;
use App\Enums\NatureOperation;
use App\Enums\PrixOrigine;
use App\Enums\ProduitStatut;
use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\ProduitVariante;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PdvCheckoutService
{
    public function __construct(
        private readonly VehiculeCapaciteService $vehiculeCapaciteService,
        private readonly SolvabiliteService $solvabiliteService,
    ) {}

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
            // Verrou de ligne sur le véhicule le temps de la transaction : sans cela, deux
            // requêtes concurrentes pour le même véhicule passeraient toutes les deux le
            // contrôle de solvabilité (aucune facture bloquante encore visible pour l'une comme
            // pour l'autre) puis créeraient chacune une commande — exactement le doublon que ce
            // contrôle doit empêcher (cf. section « concurrence » de la règle métier).
            if (! empty($data['vehicule_id'])) {
                Vehicule::whereKey($data['vehicule_id'])->lockForUpdate()->first();
            }

            // Même règle de solvabilité que le back-office (CommandeVenteController::store()),
            // sur le même service — le PDV créait auparavant sa facture sans AUCUN contrôle
            // d'impayés, quel que soit le paramétrage de l'organisation (trou identifié le
            // 18/08/2026). Exécuté sous le verrou ci-dessus pour rester fiable en concurrence.
            $this->solvabiliteService->enforcerOuEchouer(
                $user->organization_id,
                $data['vehicule_id'] ?? null,
                $data['client_id'] ?? null,
            );

            $client = ! empty($data['client_id']) ? Client::query()->select(['id', 'type'])->find($data['client_id']) : null;

            // Grossiste : tarification catégorie × mode (Enlèvement/Livraison), non pertinente au
            // comptoir — jamais servi via PDV, cf. docs/grossiste.md. Décision de périmètre
            // (05/09/2026) : passer par une commande de vente (CommandeVenteController), seul
            // point d'entrée qui connaît le mode de remise.
            if ($client?->type === ClientType::GROSSISTE) {
                throw ValidationException::withMessages([
                    'client_id' => 'Un client Grossiste ne peut pas être servi au comptoir — créez une commande de vente.',
                ]);
            }

            // Chargé une fois ici (organisation vérifiée) — sert à la fois à dériver
            // nature_operation (DISTRIBUTION_CLIENT exige livraison_logistique=true, jamais la
            // seule présence d'un véhicule, cf. NatureOperation::deriverParDefaut()) et à calculer
            // l'éligibilité aux commissions selon la nature réellement résolue ci-dessous.
            $vehiculePourNature = ! empty($data['vehicule_id'])
                ? Vehicule::query()->where('organization_id', $user->organization_id)->find($data['vehicule_id'])
                : null;
            $natureOperation = NatureOperation::deriverParDefaut($client?->type, $vehiculePourNature);

            $context = VehiculeCommandeContextResolver::resolve($data['vehicule_id'] ?? null, $data['client_id'] ?? null, $natureOperation);
            [$lignesData, $total, $stockTrackedVarianteIds, $autoriseVenteStockNegatif] = $this->buildLignes($data['lignes'], $user->organization_id, (string) $siteId, $context->modeTarification, $context->categorieTarifaireVehicule, $client);

            $commande = CommandeVente::create([
                'organization_id' => $user->organization_id,
                'site_id' => $siteId,
                'vehicule_id' => $data['vehicule_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'client_vehicule_id' => $data['client_vehicule_id'] ?? null,
                'total_commande' => $total,
                'mode_tarification_snapshot' => $context->modeTarification->value,
                'commission_eligible_snapshot' => $context->commissionEligible,
                'nature_operation' => $natureOperation->value,
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
                    allowNegative: $autoriseVenteStockNegatif,
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
        // Parametre::isVentesAutorisationSaisieDessousQteMax) — comportement historique
        // préservé tel quel. Le dépassement de capacité, lui, n'est plus tolérable pour
        // personne (cf. VehiculeCapaciteService), PDV compris.
        $this->vehiculeCapaciteService->verifier($vehicule, $data['lignes'], 'quantite', false);
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
            $variante = ProduitVariante::with('produit.produitType')->find($ligne['variante_id']);

            if (! $variante || $variante->produit_id !== $ligne['produit_id']) {
                throw ValidationException::withMessages([
                    'lignes' => 'La variante sélectionnée est introuvable.',
                ]);
            }
        } else {
            $produit = Produit::with(['variantes', 'produitType'])
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
    private function buildLignes(array $lignes, string $orgId, string $siteId, ModeTarification $mode, ?CategorieTarifaireVehicule $categorieTarifaire = null, ?Client $client = null): array
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

        // Politique globale d'organisation (Paramètres > Paramètres produits, DSI 23/08/2026) —
        // jamais un réglage par produit : soit toutes les ventes de l'organisation peuvent
        // dépasser le disponible, soit aucune. Lue une seule fois, jamais par ligne.
        $autoriseVenteStockNegatif = Parametre::isVentesAutoriseesSansStock($orgId);

        $lignesData = [];
        $stockTrackedVarianteIds = [];
        $total = 0;

        foreach ($resolved as $item) {
            $variante = $item['variante'];
            $produit = $variante->produit;
            $qte = $item['qte'];

            if ($produit->produitType->gere_stock) {
                $stockTrackedVarianteIds[] = $variante->id;
                // Aucune ligne VarianteStock pour cette variante sur ce site : 0 disponible,
                // jamais de repli sur l'agrégat legacy du produit parent (qui ne renseigne
                // sur aucune agence en particulier) — cf. MouvementStockService::quantiteDisponible().
                // Disponible = physique moins réservé (commandes vente confirmées, pas encore
                // chargées, cf. StockReservationService, 24/08/2026) : le PDV ne doit jamais
                // pouvoir vendre un stock déjà promis à une commande vente en attente de
                // chargement — même règle que verifierDisponibiliteLignes(), calculée ici plutôt
                // que via ce service pour rester dans le même verrou lockForUpdate() groupé
                // (cf. commentaire de buildLignes() ci-dessous).
                $stockSite = $stocksSite->get($variante->id);
                $disponible = (int) ($stockSite?->qte_stock ?? 0) - (int) ($stockSite?->qte_reservee ?? 0);

                // La vente continue même sous le disponible si la politique globale l'autorise —
                // le stock devient négatif au lieu d'être refusé, jamais de clamp silencieux
                // (cf. MouvementStockService::appliquer()).
                if (! $autoriseVenteStockNegatif && $disponible < $qte) {
                    throw ValidationException::withMessages([
                        'lignes' => "Stock insuffisant pour « {$produit->nom} » sur ce site (disponible : {$disponible}, demandé : {$qte}).",
                    ]);
                }
            }

            // Le PDV n'a jamais reçu de prix du frontend (contrairement au back-office) : ce
            // resolver est donc toujours la seule source du prix de vente ici, fabricable ou
            // non — il retombe lui-même sur prix_vente hors du cas fabricable+client. Fabricable
            // + client : ce prix gouverne SEUL le total, sans passer par le mode de tarification
            // véhicule/client (qui basculerait sinon un client Externe entier sur prix_usine,
            // ignorant le prix_externe qu'on vient de résoudre) — cf. CommandeVenteController::
            // buildLignesDataAndTotal() pour le même correctif côté back-office.
            $ligneFabricablePourClient = PrixVenteNatureResolver::estFabricable($variante) && $client;
            $prixVente = PrixVenteNatureResolver::resolve($variante, $client);
            $prixUsine = PrixUsineResolver::resolve($variante, $categorieTarifaire);
            $appliquerPrixVente = $ligneFabricablePourClient || $mode === ModeTarification::PRIX_VENTE;
            $totalLigne = $qte * ($appliquerPrixVente ? $prixVente : $prixUsine);
            $prixOrigine = $ligneFabricablePourClient
                ? PrixVenteNatureResolver::resolveOrigine($variante, $client)
                : ($appliquerPrixVente ? PrixOrigine::VENTE : PrixOrigine::USINE);

            $lignesData[] = [
                'variante_id' => $variante->id,
                'quantite_demandee' => $qte,
                'prix_usine_snapshot' => $prixUsine,
                'prix_origine_snapshot' => $prixOrigine->value,
                'prix_vente_snapshot' => $prixVente,
                'total_ligne' => $totalLigne,
                'libelle_snapshot' => $variante->libelle !== '' ? "{$produit->nom} — {$variante->libelle}" : $produit->nom,
            ];

            $total += $totalLigne;
        }

        return [$lignesData, $total, $stockTrackedVarianteIds, $autoriseVenteStockNegatif];
    }
}
