<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutCommission;
use App\Models\CommissionVente;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionVenteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        $orgId   = auth()->user()->organization_id;
        $periode = $request->input('periode', 'month');

        $query = CommissionVente::with([
                'commande.site',
                'vehicule',
                'versements.creator',
            ])
            ->where('organization_id', $orgId);

        match ($periode) {
            'today' => $query->whereDate('created_at', now()),
            'week'  => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month),
            default => null,
        };

        $commissions = $query->orderByDesc('created_at')
            ->get()
            ->map(fn (CommissionVente $c) => [
                'id'                 => $c->id,
                'commande_id'        => $c->commande_vente_id,
                'commande_reference' => $c->commande?->reference,
                'site_nom'           => $c->commande?->site?->nom,
                'vehicule_nom'       => $c->vehicule?->nom_vehicule,
                'immatriculation'    => $c->vehicule?->immatriculation,
                'livreur_nom'        => $c->livreur_nom,
                'taux_commission'              => (float) $c->taux_commission,
                'taux_commission_proprietaire' => (float) $c->taux_commission_proprietaire,
                'montant_commande'             => (float) $c->montant_commande,
                'montant_commission'           => (float) $c->montant_commission,
                'montant_part_livreur'         => (float) $c->montant_part_livreur,
                'montant_part_proprietaire'    => (float) $c->montant_part_proprietaire,
                'montant_verse'                => (float) $c->montant_verse,
                'montant_verse_livreur'        => (float) $c->montant_verse_livreur,
                'montant_verse_proprietaire'   => (float) $c->montant_verse_proprietaire,
                'montant_restant'              => (float) $c->montant_restant,
                'montant_restant_livreur'      => (float) $c->montant_restant_livreur,
                'montant_restant_proprietaire' => (float) $c->montant_restant_proprietaire,
                'statut'             => $c->statut?->value,
                'statut_label'       => $c->statut_label,
                'is_versee'          => $c->isVersee(),
                'is_annulee'         => $c->isAnnulee(),
                'created_at'         => $c->created_at?->format('d/m/Y'),
                'versements'         => $c->versements
                    ->sortByDesc(fn ($v) => $v->created_at?->timestamp ?? 0)
                    ->values()
                    ->map(fn ($v) => [
                        'id'                => $v->id,
                        'date_versement'    => $v->date_versement?->format('d/m/Y'),
                        'beneficiaire'      => $v->beneficiaire,
                        'beneficiaire_label'=> $v->beneficiaire === 'proprietaire' ? 'Proprietaire' : 'Livreur',
                        'mode_paiement'     => $v->mode_paiement instanceof ModePaiement
                            ? $v->mode_paiement->label()
                            : (string) $v->mode_paiement,
                        'montant'           => (float) $v->montant,
                        'note'              => $v->note,
                        'created_by'        => $v->creator?->name,
                    ])
                    ->values()
                    ->all(),
            ]);

        $enAttente  = $commissions->where('statut', StatutCommission::EN_ATTENTE->value);
        $partielles = $commissions->where('statut', StatutCommission::PARTIELLE->value);
        $versees    = $commissions->where('statut', StatutCommission::VERSEE->value);

        $totaux = [
            'total_a_verser'      => $commissions
                ->whereNotIn('statut', [StatutCommission::VERSEE->value, StatutCommission::ANNULEE->value])
                ->sum('montant_restant'),
            'nb_en_attente'       => $enAttente->count(),
            'montant_en_attente'  => $enAttente->sum('montant_commission'),
            'nb_partielles'       => $partielles->count(),
            'montant_partielles'  => $partielles->sum('montant_restant'),
            'nb_versees'          => $versees->count(),
            // Montant effectivement verse (inclut les versements partiels).
            'montant_versees'     => $commissions->sum('montant_verse'),
        ];

        return Inertia::render('Commissions/Index', [
            'commissions'    => $commissions->values(),
            'totaux'         => $totaux,
            'modes_paiement' => ModePaiement::options(),
            'periode'        => $periode,
        ]);
    }
}
