<?php

namespace App\Http\Controllers\Comptabilite\CommissionMonitoring;

use App\Http\Controllers\Controller;
use App\Services\Commission\CommissionMonitoringService;
use App\Services\SiteScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Relance (unitaire ou multiple) des anomalies sélectionnées dans Commissions → Monitoring —
 * via le moteur officiel uniquement (CommissionMonitoringService::relancer()). Chaque opération
 * est relancée indépendamment : un succès partiel est rapporté tel quel, jamais annulé.
 */
class RelancerCommissionMonitoringController extends Controller
{
    public function __invoke(Request $request, CommissionMonitoringService $monitoring, SiteScopeService $siteScope): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('commissions.update'), 403);

        $valides = $request->validate([
            'anomalies' => ['required', 'array', 'min:1', 'max:200'],
            'anomalies.*' => ['required', 'string', 'max:200'],
        ]);

        $resultat = $monitoring->relancer(
            $user->organization_id,
            array_values(array_unique($valides['anomalies'])),
            $user->id,
            $user->isAdmin() ? null : $siteScope->accessibleSiteIds($user),
        );

        $regularisees = count($resultat['regularisees']);
        $sansObjet = count($resultat['sans_objet']);
        $echecs = $resultat['echecs'];

        $redirection = back();

        if ($echecs !== []) {
            $premier = $echecs[0];
            $message = count($echecs) === 1
                ? "La génération a de nouveau échoué pour {$premier['reference']} : {$premier['message']}"
                : sprintf('%d anomalie(s) toujours en échec (ex. %s : %s).', count($echecs), $premier['reference'], $premier['message']);
            if ($regularisees > 0) {
                $message = "{$regularisees} commission(s) régularisée(s). {$message}";
            }

            return $redirection->withErrors(['relance' => $message]);
        }

        if ($regularisees === 0 && $sansObjet === 0) {
            return $redirection->withErrors(['relance' => 'Aucune anomalie ouverte à relancer dans la sélection.']);
        }

        $message = $regularisees === 1 && $sansObjet === 0
            ? 'Commission régularisée avec succès.'
            : trim(($regularisees > 0 ? "{$regularisees} commission(s) régularisée(s). " : '').($sansObjet > 0 ? "{$sansObjet} anomalie(s) désormais sans objet." : ''));

        return $redirection->with('success', $message);
    }
}
