<?php

namespace App\Services\Comptabilite;

use App\Contracts\Rh\SourceDonneesPaie;
use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaie;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validation d'une période de paie depuis Comptabilité > Paiement salaire : elle rend les salaires
 * du mois payables (PeriodePayabilityChecker) et verrouille les variables de paie.
 *
 * La période doit porter la version de transmission RH en vigueur du mois (ReprisePaieService,
 * ADR 0041) : jamais une version remplacée entre-temps. Le verrou sur l'organisation est le même que
 * celui de la transmission RH : une transmission et une validation simultanées sont sérialisées.
 *
 * Une période reprise est au statut « calculé » : la validation la fait passer à « validé », dans
 * le respect des transitions de StatutPeriodePaie. Aucun recalcul n'est fait : on valide les
 * montants affichés à l'écran.
 */
class ValidationPeriodePaieService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly SourceDonneesPaie $source,
    ) {}

    public function valider(PaiePeriode $periode, User $acteur): PaiePeriode
    {
        // Gate::before du super administrateur : l'organisation n'est pas garantie par la Policy.
        abort_if($periode->organization_id !== $acteur->organization_id, 403);

        return DB::transaction(function () use ($periode, $acteur) {
            Organization::whereKey($periode->organization_id)->lockForUpdate()->firstOrFail();
            $periode = PaiePeriode::whereKey($periode->getKey())->lockForUpdate()->firstOrFail();

            $statutInitial = $periode->statut;

            if (! self::estValidable($periode)) {
                throw new InvalidArgumentException(
                    "La période {$periode->labelPeriode()} ne peut pas être validée (statut actuel : {$statutInitial->label()})."
                );
            }

            if ($periode->transmission_paie_id === null) {
                throw new InvalidArgumentException("Reprenez d'abord la transmission RH de {$periode->labelPeriode()} : la paie se prépare à partir de la version transmise par les RH.");
            }

            $courante = $this->source->transmissionCourante($periode->organization_id, $periode->annee, $periode->mois);
            if ($courante?->id !== $periode->transmission_paie_id) {
                throw new InvalidArgumentException(
                    'Les RH ont transmis une version plus récente'.($courante !== null ? " (version {$courante->version})" : '').' : reprenez-la avant de valider.'
                );
            }

            if (! PaieLigne::where('paie_periode_id', $periode->getKey())->exists()) {
                throw new InvalidArgumentException("La période {$periode->labelPeriode()} ne contient aucune ligne de paie.");
            }

            if ($periode->statut === StatutPeriodePaie::BROUILLON) {
                $periode->update(['statut' => StatutPeriodePaie::CALCULE]);
            }

            $periode->update(['statut' => StatutPeriodePaie::VALIDE_RH]);

            $this->auditLog->record($periode, AuditEvent::VALIDATED, $acteur,
                ['statut' => $statutInitial->value],
                ['statut' => StatutPeriodePaie::VALIDE_RH->value, 'transmission_version' => $courante->version],
                [
                    'module' => 'salaires',
                    'description' => "Période de paie {$periode->labelPeriode()} validée (transmission RH version {$courante->version})",
                ],
            );

            return $periode;
        });
    }

    /** Statut seul, sans contrôle des lignes ni des droits : sert aussi à l'affichage du bouton. */
    public static function estValidable(PaiePeriode $periode): bool
    {
        return in_array($periode->statut, [StatutPeriodePaie::BROUILLON, StatutPeriodePaie::CALCULE], true);
    }
}
