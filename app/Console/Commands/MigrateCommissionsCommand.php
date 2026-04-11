<?php

namespace App\Console\Commands;

use App\Enums\StatutPartCommission;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPayment;
use App\Models\CommissionPaymentItem;
use App\Models\VersementCommissionLogistique;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migre les données existantes vers le nouveau système :
 *  1. Calcule earned_at / unlock_at sur toutes les parts existantes.
 *  2. Convertit les versements legacy (versements_commission_logistique)
 *     en CommissionPayment + CommissionPaymentItem.
 *  3. Recalcule les statuts.
 *  4. Vérifie la cohérence des totaux.
 *
 * Usage :
 *   php artisan commissions:migrate [--dry-run]
 *
 * Idempotent : les parts déjà migrées (unlock_at renseigné) sont ignorées.
 * Les versements déjà convertis (marqués migrated) sont ignorés.
 */
class MigrateCommissionsCommand extends Command
{
    protected $signature = 'commissions:migrate
                            {--dry-run : Affiche ce qui serait fait, sans modifier la base}';

    protected $description = 'Migre les commissions logistiques existantes vers le nouveau système (unlock_at + CommissionPayment)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('=== DRY-RUN : aucune modification ne sera appliquée ===');
        }

        $this->line('');
        $this->info('Étape 1 — Calcul earned_at / unlock_at sur les parts existantes');
        $nbPartsUpdated = $this->migrerUnlockAt($dryRun);
        $this->line("  → {$nbPartsUpdated} part(s) mise(s) à jour");

        $this->line('');
        $this->info('Étape 2 — Conversion des versements legacy en paiements groupés');
        [$nbPayments, $nbItems] = $this->migrerVersements($dryRun);
        $this->line("  → {$nbPayments} paiement(s) créé(s), {$nbItems} allocation(s)");

        $this->line('');
        $this->info('Étape 3 — Recalcul des statuts');
        $nbStatuts = $this->recalculerStatuts($dryRun);
        $this->line("  → {$nbStatuts} part(s) recalculée(s)");

        $this->line('');
        $this->info('Étape 4 — Vérification de cohérence');
        $ok = $this->verifierCoherence();
        if ($ok) {
            $this->info('  ✓ Totaux cohérents');
        } else {
            $this->error('  ✗ Divergences détectées — vérifiez les logs');
        }

        $this->line('');
        $this->info($dryRun ? 'DRY-RUN terminé — aucune modification.' : 'Migration terminée.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    // ── Étape 1 : earned_at / unlock_at ──────────────────────────────────────

    private function migrerUnlockAt(bool $dryRun): int
    {
        $parts = CommissionLogistiquePart::whereNull('unlock_at')
            ->with('commission.transfert')
            ->get();

        $nb = 0;

        foreach ($parts as $part) {
            $transfert = $part->commission?->transfert;

            // earned_at = date réception réelle, sinon created_at de la commission
            $earnedAt = $transfert?->date_arrivee_reelle
                ? \Illuminate\Support\Carbon::instance($transfert->date_arrivee_reelle)
                : ($part->commission?->created_at ?? now());

            $unlockAt = CommissionLogistiquePart::calculerUnlockAt(
                $part->type_beneficiaire,
                $earnedAt
            );

            if ($dryRun) {
                $this->line("  [dry] Part #{$part->id} : earned_at={$earnedAt->toDateString()} unlock_at={$unlockAt->toDateString()}");
            } else {
                $part->earned_at = $earnedAt->toDateString();
                $part->unlock_at = $unlockAt->toDateString();
                $part->saveQuietly();
            }

            $nb++;
        }

        return $nb;
    }

    // ── Étape 2 : versements legacy → CommissionPayment ──────────────────────

    private function migrerVersements(bool $dryRun): array
    {
        // Regroupe par (part → commission → vehicule) + (created_by + date + mode) pour
        // simuler un paiement groupé. Chaque versement legacy est mappé à un paiement 1:1.
        $versements = VersementCommissionLogistique::with([
            'part.commission.transfert',
            'part.commission.vehicule',
        ])->get();

        $nbPayments = 0;
        $nbItems    = 0;

        foreach ($versements as $versement) {
            $part     = $versement->part;
            $vehicule = $part?->commission?->vehicule;

            if (! $part || ! $vehicule) {
                $this->warn("  Versement #{$versement->id} : pas de part/véhicule associé, ignoré");
                continue;
            }

            // Vérifier si déjà migré (un PaymentItem lié à cette part existe avec le même montant + date)
            $dejaMigre = CommissionPaymentItem::where('part_id', $part->id)
                ->whereHas('payment', function ($q) use ($versement) {
                    $q->where('paid_at', $versement->date_versement)
                      ->where('created_by', $versement->created_by);
                })
                ->exists();

            if ($dejaMigre) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry] Versement #{$versement->id} : {$versement->montant} GNF → Part #{$part->id} ({$part->beneficiaire_nom})");
                $nbPayments++;
                $nbItems++;
                continue;
            }

            DB::transaction(function () use ($versement, $part, $vehicule, &$nbPayments, &$nbItems) {
                $payment = CommissionPayment::create([
                    'organization_id'  => $vehicule->organization_id,
                    'vehicule_id'      => $vehicule->id,
                    'livreur_id'       => $part->type_beneficiaire === 'livreur' ? $part->livreur_id : null,
                    'proprietaire_id'  => $part->type_beneficiaire === 'proprietaire' ? $part->proprietaire_id : null,
                    'beneficiary_type' => $part->type_beneficiaire,
                    'beneficiary_nom'  => $part->beneficiaire_nom,
                    'montant'          => $versement->montant,
                    'mode_paiement'    => $versement->mode_paiement,
                    'note'             => $versement->note ? "[migré] {$versement->note}" : '[migré depuis versements legacy]',
                    'paid_at'          => $versement->date_versement,
                    'created_by'       => $versement->created_by,
                ]);

                CommissionPaymentItem::create([
                    'payment_id'       => $payment->id,
                    'part_id'          => $part->id,
                    'amount_allocated' => $versement->montant,
                ]);

                $nbPayments++;
                $nbItems++;
            });
        }

        return [$nbPayments, $nbItems];
    }

    // ── Étape 3 : recalcul statuts ────────────────────────────────────────────

    private function recalculerStatuts(bool $dryRun): int
    {
        if ($dryRun) {
            return CommissionLogistiquePart::count();
        }

        $nb = 0;
        CommissionLogistiquePart::chunkById(200, function ($parts) use (&$nb) {
            foreach ($parts as $part) {
                $part->recalculStatut();
                $nb++;
            }
        });

        return $nb;
    }

    // ── Étape 4 : vérification ────────────────────────────────────────────────

    private function verifierCoherence(): bool
    {
        // Total versé dans les anciens versements
        $totalLegacy = (float) VersementCommissionLogistique::sum('montant');

        // Total alloué dans les nouveaux payment_items qui sont marqués [migré]
        $totalNew = (float) CommissionPaymentItem::whereHas('payment', function ($q) {
            $q->where('note', 'like', '[migré%');
        })->sum('amount_allocated');

        $diff = abs($totalLegacy - $totalNew);

        if ($diff > 0.01) {
            $this->error(sprintf(
                '  Total legacy : %.2f | Total migré : %.2f | Diff : %.2f',
                $totalLegacy,
                $totalNew,
                $diff
            ));
            return false;
        }

        $this->line(sprintf(
            '  Total legacy : %.2f GNF | Total migré : %.2f GNF ✓',
            $totalLegacy,
            $totalNew
        ));

        return true;
    }
}
