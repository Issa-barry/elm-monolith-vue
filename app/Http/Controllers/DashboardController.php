<?php

namespace App\Http\Controllers;

use App\Enums\StatutFactureVente;
use App\Models\FactureVente;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $orgId = auth()->user()->organization_id;

        // ── Agrégats des factures de vente ─────────────────────────────────────
        $row = FactureVente::where('organization_id', $orgId)
            ->selectRaw("
                COUNT(*) as total_count,
                COALESCE(SUM(montant_net), 0) as total_montant,
                COALESCE(SUM(CASE WHEN statut_facture = 'payee'    THEN 1 ELSE 0 END), 0) as payees_count,
                COALESCE(SUM(CASE WHEN statut_facture = 'payee'    THEN montant_net ELSE 0 END), 0) as payees_montant,
                COALESCE(SUM(CASE WHEN statut_facture = 'impayee'  THEN 1 ELSE 0 END), 0) as impayees_count,
                COALESCE(SUM(CASE WHEN statut_facture = 'annulee'  THEN 1 ELSE 0 END), 0) as annulees_count,
                COALESCE(SUM(CASE WHEN statut_facture IN ('impayee','partiel') THEN montant_net ELSE 0 END), 0) as montant_actif
            ")
            ->first();

        // Encaissé sur les factures encore actives (impayée + partielle)
        $encaisseActif = DB::table('encaissements_ventes as ev')
            ->join('factures_ventes as fv', 'fv.id', '=', 'ev.facture_vente_id')
            ->where('fv.organization_id', $orgId)
            ->whereNull('fv.deleted_at')
            ->whereIn('fv.statut_facture', [
                StatutFactureVente::IMPAYEE->value,
                StatutFactureVente::PARTIEL->value,
            ])
            ->sum('ev.montant');

        $resteAEncaisser = max(0, (float) $row->montant_actif - (float) $encaisseActif);

        return Inertia::render('Dashboard', [
            'stats_factures' => [
                'total_count'      => (int) $row->total_count,
                'total_montant'    => (float) $row->total_montant,
                'payees_count'     => (int) $row->payees_count,
                'payees_montant'   => (float) $row->payees_montant,
                'impayees_count'   => (int) $row->impayees_count,
                'annulees_count'   => (int) $row->annulees_count,
                'reste_a_encaisser'=> $resteAEncaisser,
            ],
        ]);
    }
}
