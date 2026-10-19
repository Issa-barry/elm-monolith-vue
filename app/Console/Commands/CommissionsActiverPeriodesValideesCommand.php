<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Enums\StatutCommission;
use App\Enums\StatutPeriodePaiement;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistiquePart;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementPeriode;
use App\Services\AuditLogService;
use App\Services\CommissionAdjustmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rattrapage de l'incident du 17/10/2026 : une validation de période interrompue en pleine
 * activation (PAY-202610-P1-LIV) avait laissé la période « Validée » avec des commissions encore
 * « À valider », donc jamais payables. Liste les périodes validées/clôturées dont les fiches portent
 * encore des commissions CREEE et ne les active qu'avec --appliquer, par le même mécanisme que la
 * validation (CommissionAdjustmentService::activerCommissionsCreees()). Idempotente.
 *
 * La comptabilisation des fiches, interrompue au même moment, se rattrape ensuite avec
 * `comptabilite:rattraper --type=fiche`.
 */
class CommissionsActiverPeriodesValideesCommand extends Command
{
    protected $signature = 'commissions:activer-periodes-validees
        {--organization= : ID d\'organisation ; toutes si omis}
        {--appliquer : Active les commissions (sinon simple aperçu)}';

    protected $description = 'Active les commissions restées « À valider » sur des périodes déjà validées (aperçu par défaut).';

    public function handle(AuditLogService $auditLog): int
    {
        $appliquer = (bool) $this->option('appliquer');

        $periodes = PaiementPeriode::whereIn('statut', [StatutPeriodePaiement::VALIDEE->value, StatutPeriodePaiement::CLOTUREE->value])
            ->when($this->option('organization'), fn ($q, $org) => $q->where('organization_id', $org))
            ->orderBy('date_debut')
            ->get();

        $lignes = [];
        foreach ($periodes as $periode) {
            $creees = $this->compterCreees($periode, CommissionEnveloppePart::class)
                + $this->compterCreees($periode, CommissionLogistiquePart::class);
            if ($creees === 0) {
                continue;
            }

            $lignes[] = [$periode->reference, $periode->type->value, $periode->statut->value, $creees];

            if ($appliquer) {
                DB::transaction(function () use ($periode, $creees, $auditLog) {
                    CommissionAdjustmentService::activerCommissionsCreees($periode);
                    CommissionAdjustmentService::activerCommissionsLogistiqueCreees($periode);

                    $auditLog->record($periode, AuditEvent::STATUS_CHANGED, null, null, null, [
                        'module' => 'periodes_paiement',
                        'site_id' => $periode->site_id,
                        'description' => "Période {$periode->reference} : {$creees} commission(s) restée(s) « À valider » après validation, activée(s) par rattrapage",
                    ]);
                });
            }
        }

        if ($lignes === []) {
            $this->info('Aucune période validée ne porte de commission « À valider ».');

            return self::SUCCESS;
        }

        $this->table(['Période', 'Type', 'Statut', 'Commissions « À valider »'], $lignes);
        $this->line(count($lignes).' période(s) '.($appliquer
            ? 'rattrapée(s). Lancer ensuite : php artisan comptabilite:rattraper --type=fiche'
            : 'à rattraper — relancer avec --appliquer pour activer.'));

        return self::SUCCESS;
    }

    /** @param  class-string<CommissionEnveloppePart|CommissionLogistiquePart>  $classePart */
    private function compterCreees(PaiementPeriode $periode, string $classePart): int
    {
        $ids = PaiementFicheLigne::whereIn('fiche_id', $periode->fiches()->pluck('id'))
            ->where('source_type', $classePart)
            ->pluck('source_id')
            ->unique();

        return $ids->chunk(500)->sum(fn ($lot) => $classePart::whereIn('id', $lot->values())
            ->where('statut', StatutCommission::CREEE->value)
            ->count());
    }
}
