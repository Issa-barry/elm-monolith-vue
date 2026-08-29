<?php

namespace App\Services;

use App\Models\Parametre;
use Illuminate\Validation\ValidationException;

/**
 * Règle de cohérence de la dérogation d'impayés — partagée par VehiculeController et
 * ClientController pour ne jamais laisser deux implémentations indépendantes diverger (cf.
 * rapport du 28/08/2026 : le principe est identique pour les deux entités, seul le libellé du
 * message change). Extrait de VehiculeController::ensureDerogationCoherente() (décision produit
 * du 22/08/2026), désormais réutilisé tel quel pour les clients.
 *
 * Un plafond n'a de sens que s'il augmente réellement la marge par rapport au seuil standard de
 * l'organisation — sinon la dérogation ne dérogerait à rien. Une dérogation active sans plafond
 * renseigné serait un chèque en blanc sans plafond réel (SolvabiliteService retomberait alors
 * sur le seuil global en filet de sécurité, mais l'UI ne doit jamais laisser croire à une
 * dérogation active qui n'en est pas une).
 */
class DerogationImpayesService
{
    public static function validerCoherence(bool $autorisee, ?int $seuil, string $orgId, string $libelleEntite): void
    {
        if (! $autorisee) {
            return;
        }

        if ($seuil === null || $seuil <= 0) {
            throw ValidationException::withMessages([
                'derogation_impayes_autorisee' => "Impossible d'activer la dérogation : renseignez un plafond d'impayés autorisé pour {$libelleEntite}.",
            ]);
        }

        $seuilStandard = Parametre::getVentesSeuilImpayesMax($orgId);
        if ($seuil < $seuilStandard) {
            throw ValidationException::withMessages([
                'derogation_impayes_autorisee' => 'Le plafond doit être supérieur ou égal au seuil standard actuel ('
                    .number_format($seuilStandard, 0, ',', ' ').' GNF).',
            ]);
        }
    }
}
