<?php

namespace App\Services\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaie;
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
 * Une période générée par PaieCalculService::getOrGenererPeriode() a déjà ses lignes calculées
 * mais reste au statut « brouillon » : la validation la fait passer par « calculé » puis « validé »,
 * dans le respect des transitions de StatutPeriodePaie. Aucun recalcul n'est fait : on valide les
 * montants affichés à l'écran.
 */
class ValidationPeriodePaieService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function valider(PaiePeriode $periode, User $acteur): PaiePeriode
    {
        return DB::transaction(function () use ($periode, $acteur) {
            $periode = PaiePeriode::whereKey($periode->getKey())->lockForUpdate()->firstOrFail();

            // Gate::before du super administrateur : l'organisation n'est pas garantie par la Policy.
            abort_if($periode->organization_id !== $acteur->organization_id, 403);

            $statutInitial = $periode->statut;

            if (! self::estValidable($periode)) {
                throw new InvalidArgumentException(
                    "La période {$periode->labelPeriode()} ne peut pas être validée (statut actuel : {$statutInitial->label()})."
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
                ['statut' => StatutPeriodePaie::VALIDE_RH->value],
                [
                    'module' => 'salaires',
                    'description' => "Période de paie {$periode->labelPeriode()} validée",
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
