<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutFactureVente;
use App\Models\FactureVente;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FactureVenteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        $periode = $request->input('periode', 'today');

        $query = FactureVente::with([
            'commande.vehicule',
            'commande.client',
            'commande.site',
            'encaissements',
        ])
            ->where('organization_id', $orgId)
            ->whereNotNull('reference')
            ->where('reference', 'not like', 'TMP-%');

        match ($periode) {
            'today' => $query->whereDate('created_at', Carbon::today()),
            'week' => $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
            'month' => $query->whereYear('created_at', Carbon::now()->year)
                ->whereMonth('created_at', Carbon::now()->month),
            default => null, // 'all' : pas de filtre date
        };

        $factures = $query->orderByDesc('created_at')
            ->get()
            ->map(fn (FactureVente $f) => [
                'id' => $f->id,
                'reference' => $f->reference,
                'commande_id' => $f->commande_vente_id,
                'vehicule_nom' => $f->commande?->vehicule?->nom_vehicule,
                'client_nom' => $f->commande?->client
                    ? trim($f->commande->client->prenom.' '.$f->commande->client->nom)
                    : null,
                'site_nom' => $f->commande?->site?->nom,
                'montant_net' => (float) $f->montant_net,
                'montant_encaisse' => (float) $f->montant_encaisse,
                'montant_restant' => (float) $f->montant_restant,
                'statut_facture' => $f->statut_facture?->value,
                'statut_label' => $f->statut_label,
                'is_annulee' => $f->isAnnulee(),
                'is_payee' => $f->isPayee(),
                'created_at' => $f->created_at?->format('d/m/Y'),
            ]);

        // Totaux pour les cartes de synthèse
        $impayees = $factures->where('statut_facture', StatutFactureVente::IMPAYEE->value);
        $partielles = $factures->where('statut_facture', StatutFactureVente::PARTIEL->value);
        $payees = $factures->where('statut_facture', StatutFactureVente::PAYEE->value);

        $totaux = [
            'total_a_encaisser' => $factures
                ->whereNotIn('statut_facture', [StatutFactureVente::PAYEE->value, StatutFactureVente::ANNULEE->value])
                ->sum('montant_restant'),
            'nb_impayees' => $impayees->count(),
            'montant_impayees' => $impayees->sum('montant_restant'),
            'nb_partielles' => $partielles->count(),
            'montant_partielles' => $partielles->sum('montant_restant'),
            'nb_payees' => $payees->count(),
            'montant_payees' => $payees->sum('montant_net'),
        ];

        return Inertia::render('Factures/Index', [
            'factures' => $factures->values(),
            'totaux' => $totaux,
            'modes_paiement' => ModePaiement::options(),
            'periode' => $periode,
        ]);
    }
}
