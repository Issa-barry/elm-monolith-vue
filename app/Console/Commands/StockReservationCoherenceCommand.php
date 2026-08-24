<?php

namespace App\Console\Commands;

use App\Enums\StatutReservationStock;
use App\Models\VarianteStock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Détecte (sans jamais corriger) les écarts entre variante_stocks.qte_reservee (compteur
 * dénormalisé) et la somme des réservations ACTIVE dans stock_reservations (preuve métier) — les
 * deux DOIVENT toujours coïncider par construction (StockReservationService les mute ensemble,
 * dans la même transaction verrouillée) ; un écart signale un bug applicatif ou une intervention
 * manuelle en base, jamais un état normal. Purement informatif — même principe que
 * StockAuditLegacyCommand : aucune opération métier ne doit deviner silencieusement la valeur
 * correcte à la place d'un humain.
 */
class StockReservationCoherenceCommand extends Command
{
    protected $signature = 'stock:verifier-coherence-reservations {--organization= : ID d\'organisation ; toutes si omis}';

    protected $description = "Détecte les écarts entre variante_stocks.qte_reservee et la somme des réservations actives — ne corrige jamais automatiquement.";

    public function handle(): int
    {
        $orgId = $this->option('organization');

        $sommesActives = DB::table('stock_reservations')
            ->select('produit_variante_id', 'site_id', 'organization_id', DB::raw('SUM(quantite) as total'))
            ->where('statut', StatutReservationStock::ACTIVE->value)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->groupBy('produit_variante_id', 'site_id', 'organization_id')
            ->get()
            ->keyBy(fn ($row) => $row->produit_variante_id.'|'.$row->site_id);

        $varianteStocks = VarianteStock::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->get()
            ->keyBy(fn (VarianteStock $vs) => $vs->produit_variante_id.'|'.$vs->site_id);

        $ecarts = [];

        foreach ($varianteStocks as $key => $stock) {
            $attendu = (int) ($sommesActives[$key]->total ?? 0);
            if ($attendu !== $stock->qte_reservee) {
                $ecarts[] = [
                    'organization_id' => $stock->organization_id,
                    'produit_variante_id' => $stock->produit_variante_id,
                    'site_id' => $stock->site_id,
                    'qte_reservee_compteur' => $stock->qte_reservee,
                    'somme_reservations_actives' => $attendu,
                ];
            }
        }

        // Sens inverse : des réservations actives existent pour une variante × site dont la
        // ligne variante_stocks n'existe même plus — sinon ce cas échapperait à la boucle
        // ci-dessus, qui ne parcourt que les lignes variante_stocks existantes.
        foreach ($sommesActives as $key => $row) {
            if (isset($varianteStocks[$key])) {
                continue;
            }

            $ecarts[] = [
                'organization_id' => $row->organization_id,
                'produit_variante_id' => $row->produit_variante_id,
                'site_id' => $row->site_id,
                'qte_reservee_compteur' => 0,
                'somme_reservations_actives' => (int) $row->total,
            ];
        }

        if (empty($ecarts)) {
            $this->info('Aucun écart détecté entre qte_reservee et les réservations actives.');

            return self::SUCCESS;
        }

        $this->warn(count($ecarts)." écart(s) détecté(s) — AUCUNE correction automatique effectuée :");
        $this->table(
            ['Organisation', 'Variante', 'Site', 'qte_reservee (compteur)', 'Somme réservations actives'],
            $ecarts,
        );

        foreach ($ecarts as $ecart) {
            Log::warning('Écart de cohérence stock détecté : qte_reservee ne correspond pas à la somme des réservations actives.', $ecart);
        }

        return self::FAILURE;
    }
}
