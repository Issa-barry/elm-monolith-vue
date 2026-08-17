<?php

namespace Database\Seeders\Organizations\FelloDemo;

use App\Enums\ModePaiement;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Models\VarianteStock;
use App\Services\CommandeVenteService;
use App\Services\PdvCheckoutService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Historique de ventes PDV réaliste (40 derniers jours), généré en passant
 * par le vrai PdvCheckoutService (jamais d'insertion brute) pour que stock,
 * facture et cohérence métier soient garantis comme en production.
 *
 * Les dates sont rétro-datées après coup (created_at) car c'est ce champ
 * qu'utilisent les graphiques du tableau de bord (DashboardController :
 * commandes_ventes.created_at / factures_ventes.created_at).
 *
 * Idempotence : contrairement aux autres sous-seeders (firstOrCreate /
 * updateOrCreate), un historique de ventes aléatoire n'a pas de clé naturelle
 * à dédupliquer. On repart donc à chaque exécution d'un historique propre :
 * purge des ventes existantes de fello-demo (et seulement fello-demo, via
 * organization_id) avant régénération — sans quoi relancer le seeder
 * accumulerait indéfiniment des lots de ventes.
 */
class FelloDemoSalesSeeder extends Seeder
{
    private const JOURS_HISTORIQUE = 40;

    public function run(): void
    {
        $org = Organization::where('slug', 'fello-demo')->firstOrFail();
        $madina = Site::where('organization_id', $org->id)->where('nom', 'Boutique Madina')->firstOrFail();
        $cosa = Site::where('organization_id', $org->id)->where('nom', 'Boutique Cosa')->firstOrFail();

        $this->purgerVentesExistantes($org->id);

        $admin = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone('+224600000101'));
        $commercial = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone('+224600000102'));
        if (! $admin || $admin->organization_id !== $org->id || ! $commercial || $commercial->organization_id !== $org->id) {
            throw new \RuntimeException('Comptes staff de démo introuvables pour fello-demo.');
        }

        $clients = Client::where('organization_id', $org->id)->get();

        $stats = [
            'ventes' => ['Boutique Madina' => 0, 'Boutique Cosa' => 0],
            'modes_paiement' => [],
        ];

        $previousAuthId = Auth::id();

        try {
            $this->genererVentes($org->id, $madina, random_int(28, 38), $admin, $commercial, 0.7, $clients, $stats);
            $this->genererVentes($org->id, $cosa, random_int(18, 28), $commercial, $admin, 0.7, $clients, $stats);
        } finally {
            $previousAuthId ? Auth::loginUsingId($previousAuthId) : Auth::logout();
        }

        $this->command->newLine();
        $this->command->info('✓ Historique de ventes PDV généré :');
        $this->command->table(
            ['Site', 'Ventes'],
            collect($stats['ventes'])->map(fn ($n, $site) => [$site, $n])->values()->all()
        );
        $this->command->table(
            ['Moyen de paiement', 'Encaissements'],
            collect($stats['modes_paiement'])->map(fn ($n, $mode) => [$mode, $n])->values()->all()
        );
    }

    /**
     * Purge exclusivement les ventes déjà générées pour fello-demo (jamais
     * pour une autre organisation, toujours filtré par organization_id) —
     * cascadeOnDelete supprime en chaîne lignes/facture/encaissements ; les
     * mouvements_stock, en relation polymorphe (pas de FK), sont purgés à part.
     */
    private function purgerVentesExistantes(string $orgId): void
    {
        $commandeIds = CommandeVente::withTrashed()->where('organization_id', $orgId)->pluck('id');
        if ($commandeIds->isEmpty()) {
            return;
        }

        $ligneIds = CommandeVenteLigne::whereIn('commande_vente_id', $commandeIds)->pluck('id');

        DB::table('mouvements_stock')
            ->where('source_type', CommandeVenteLigne::class)
            ->whereIn('source_id', $ligneIds)
            ->delete();

        DB::table('commandes_ventes')->whereIn('id', $commandeIds)->delete();
    }

    /**
     * @param  float  $probaStaffPrincipal  probabilité que $staffPrincipal réalise la vente plutôt que $staffSecondaire
     * @param  array{ventes: array<string,int>, modes_paiement: array<string,int>}  $stats
     */
    private function genererVentes(
        string $orgId,
        Site $site,
        int $nbVentes,
        User $staffPrincipal,
        User $staffSecondaire,
        float $probaStaffPrincipal,
        Collection $clients,
        array &$stats,
    ): void {
        $checkoutService = app(PdvCheckoutService::class);

        for ($i = 0; $i < $nbVentes; $i++) {
            // Jointure vers produit_variantes pour retrouver le produit_id : PdvCheckoutService
            // (via PdvCheckoutRequest) exige toujours produit_id sur la ligne, variante_id n'est
            // qu'optionnel (bridge Phase 3 — pas encore de sélecteur de variante côté PDV).
            $stockDisponible = VarianteStock::where('variante_stocks.site_id', $site->id)
                ->where('variante_stocks.qte_stock', '>', 0)
                ->join('produit_variantes', 'produit_variantes.id', '=', 'variante_stocks.produit_variante_id')
                ->get([
                    'variante_stocks.produit_variante_id as variante_id',
                    'variante_stocks.qte_stock',
                    'produit_variantes.produit_id',
                ]);

            if ($stockDisponible->isEmpty()) {
                $this->command->warn("Plus de stock disponible sur {$site->nom}, arrêt après {$i} ventes.");
                break;
            }

            $varianteRows = $stockDisponible->shuffle();
            $nbLignes = min(random_int(1, 4), $varianteRows->count());
            $lignes = [];

            foreach ($varianteRows->take($nbLignes) as $row) {
                $dispo = (int) $row->qte_stock;
                $lignes[] = [
                    'produit_id' => $row->produit_id,
                    'variante_id' => $row->variante_id,
                    'quantite' => random_int(1, min(3, $dispo)),
                ];
            }

            $staff = (mt_rand(1, 100) / 100) <= $probaStaffPrincipal ? $staffPrincipal : $staffSecondaire;
            Auth::loginUsingId($staff->id);

            $avecClient = $clients->isNotEmpty() && random_int(1, 100) <= 25;
            $client = $avecClient ? $clients->random() : null;

            $date = Carbon::now()->subDays(random_int(0, self::JOURS_HISTORIQUE))
                ->setTime(random_int(9, 18), random_int(0, 59));

            try {
                $commande = $checkoutService->checkout(
                    [
                        'mode' => $client ? 'Client' : 'Vente rapide',
                        'client_id' => $client?->id,
                        'lignes' => $lignes,
                    ],
                    $staff,
                    $site->id,
                );
            } catch (ValidationException) {
                // Stock épuisé entre la lecture et l'écriture pour une ligne
                // (concurrence improbable en seeder séquentiel) : on ne casse
                // jamais tout l'historique pour une vente, on passe à la suivante.
                continue;
            }

            $this->backdaterCreation($commande, $date);
            $this->encaisser($commande, $staff, $date, $stats);

            $stats['ventes'][$site->nom]++;
        }
    }

    private function backdaterCreation(CommandeVente $commande, Carbon $date): void
    {
        $ligneIds = CommandeVenteLigne::where('commande_vente_id', $commande->id)->pluck('id');

        DB::table('commandes_ventes')->where('id', $commande->id)->update(['created_at' => $date, 'updated_at' => $date]);
        DB::table('commande_vente_lignes')->where('commande_vente_id', $commande->id)->update(['created_at' => $date, 'updated_at' => $date]);
        DB::table('factures_ventes')->where('commande_vente_id', $commande->id)->update(['created_at' => $date, 'updated_at' => $date]);
        DB::table('mouvements_stock')
            ->where('source_type', CommandeVenteLigne::class)
            ->whereIn('source_id', $ligneIds)
            ->update(['created_at' => $date, 'updated_at' => $date]);
    }

    /**
     * @param  array{ventes: array<string,int>, modes_paiement: array<string,int>}  $stats
     */
    private function encaisser(CommandeVente $commande, User $staff, Carbon $date, array &$stats): void
    {
        $facture = $commande->facture()->firstOrFail();
        $total = (float) $facture->montant_net;
        $recent = $date->diffInDays(now()) < 5;

        // Cash-and-carry en boutique : quasi toujours encaissé le jour même,
        // sauf une partie des ventes très récentes (règlement en cours).
        $paiementComplet = ! $recent || random_int(1, 100) <= 70;

        $montants = $paiementComplet
            ? [$total]
            : [round($total * (random_int(40, 90) / 100), 2)];

        // ~15% des ventes payées intégralement sont réglées en deux fois
        // (moyens de paiement différents), pour diversifier l'historique.
        if ($paiementComplet && random_int(1, 100) <= 15) {
            $part1 = round($total * 0.5, 2);
            $montants = [$part1, round($total - $part1, 2)];
        }

        foreach ($montants as $montant) {
            if ($montant <= 0) {
                continue;
            }

            $mode = $this->tirerModePaiement();

            $encaissement = $facture->encaissements()->create([
                'montant' => $montant,
                'date_encaissement' => $date->toDateString(),
                'mode_paiement' => $mode,
                'created_by' => $staff->id,
            ]);

            DB::table('encaissements_ventes')->where('id', $encaissement->id)->update(['created_at' => $date, 'updated_at' => $date]);

            $stats['modes_paiement'][$mode] = ($stats['modes_paiement'][$mode] ?? 0) + 1;
        }

        // Même logique que EncaissementVenteController::store() : premier
        // encaissement fait passer la commande en LIVREE, puis clôture
        // automatique si la facture est intégralement payée.
        $fresh = $commande->fresh();
        if ($fresh->isLivraisonEnCours()) {
            CommandeVenteService::passerEnLivree($fresh);
        }
        $fresh = $commande->fresh();
        $fresh->cloturerSiComplete();

        $fresh = $commande->fresh();
        $updates = ['updated_at' => $date];
        if ($fresh->livree_at) {
            $updates['livree_at'] = $date;
        }
        if ($fresh->closed_at) {
            $updates['closed_at'] = $date;
        }
        DB::table('commandes_ventes')->where('id', $fresh->id)->update($updates);
    }

    private function tirerModePaiement(): string
    {
        $tirage = random_int(1, 100);

        return match (true) {
            $tirage <= 60 => ModePaiement::ESPECES->value,
            $tirage <= 95 => ModePaiement::MOBILE_MONEY->value,
            $tirage <= 98 => ModePaiement::VIREMENT->value,
            default => ModePaiement::CHEQUE->value,
        };
    }
}
