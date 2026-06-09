<?php

namespace App\Http\Controllers\Api\Backoffice;

use App\Enums\StatutFactureVente;
use App\Http\Controllers\Controller;
use App\Models\FactureVente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $site = $user->sites()
            ->wherePivot('is_default', true)
            ->first()
            ?? $user->sites()->first();

        $base = FactureVente::query()
            ->where('organization_id', $user->organization_id);

        if ($site) {
            $base->where('site_id', $site->id);
        }

        $totalMontant = (clone $base)->sum('montant_net');
        $nbTotal = (clone $base)->count();
        $payeesMontant = (clone $base)->where('statut_facture', StatutFactureVente::PAYEE)->sum('montant_net');
        $nbPayees = (clone $base)->where('statut_facture', StatutFactureVente::PAYEE)->count();
        $nbImpayees = (clone $base)->where('statut_facture', StatutFactureVente::IMPAYEE)->count();
        $nbAnnulees = (clone $base)->where('statut_facture', StatutFactureVente::ANNULEE)->count();

        return response()->json([
            'total_factures' => (float) $totalMontant,
            'nb_total' => $nbTotal,
            'factures_payees' => (float) $payeesMontant,
            'nb_payees' => $nbPayees,
            'reste_encaisser' => (float) ($totalMontant - $payeesMontant),
            'nb_impayees' => $nbImpayees,
            'nb_annulees' => $nbAnnulees,
        ]);
    }
}
