<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutCommission;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionVenteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        $orgId  = auth()->user()->organization_id;
        $periode = $request->input('periode', 'month');

        $query = CommissionVente::with(['commande.site', 'vehicule.equipe', 'vehicule.proprietaire', 'parts'])
            ->where('organization_id', $orgId);

        match ($periode) {
            'today' => $query->whereDate('created_at', now()),
            'week'  => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month),
            default => null,
        };

        $commissions = $query->orderByDesc('created_at')
            ->get()
            ->map(fn (CommissionVente $c) => $this->mapCommission($c));

        $actives = $commissions->whereNotIn('statut', [StatutCommission::ANNULEE->value]);

        $totaux = [
            'total_a_verser'    => $actives->whereNotIn('statut', [StatutCommission::VERSEE->value])->sum('montant_restant'),
            'nb_en_attente'     => $commissions->where('statut', StatutCommission::EN_ATTENTE->value)->count(),
            'montant_en_attente'=> $commissions->where('statut', StatutCommission::EN_ATTENTE->value)->sum('montant_commission_totale'),
            'nb_partielles'     => $commissions->where('statut', StatutCommission::PARTIELLE->value)->count(),
            'montant_partielles'=> $commissions->where('statut', StatutCommission::PARTIELLE->value)->sum('montant_restant'),
            'nb_versees'        => $commissions->where('statut', StatutCommission::VERSEE->value)->count(),
            'montant_versees'   => $commissions->where('statut', StatutCommission::VERSEE->value)->sum('montant_verse'),
        ];

        return Inertia::render('Commissions/Index', [
            'commissions' => $commissions->values(),
            'totaux'      => $totaux,
            'periode'     => $periode,
        ]);
    }

    public function show(CommissionVente $commission_vente): Response
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        abort_unless(
            $commission_vente->organization_id === auth()->user()->organization_id,
            403,
            'Accès refusé.'
        );

        $commission_vente->load([
            'commande.site',
            'vehicule.equipe',
            'vehicule.proprietaire',
            'parts.versements.creator',
        ]);

        return Inertia::render('Commissions/Show', [
            'commission'    => $this->mapCommission($commission_vente, withParts: true),
            'modes_paiement'=> ModePaiement::options(),
        ]);
    }

    // ── Mapping ───────────────────────────────────────────────────────────────

    private function mapCommission(CommissionVente $c, bool $withParts = false): array
    {
        $data = [
            'id'                       => $c->id,
            'commande_id'              => $c->commande_vente_id,
            'commande_reference'       => $c->commande?->reference,
            'site_nom'                 => $c->commande?->site?->nom,
            'vehicule_nom'             => $c->vehicule?->nom_vehicule,
            'immatriculation'          => $c->vehicule?->immatriculation,
            'equipe_nom'               => $c->vehicule?->equipe?->nom,
            'proprietaire_nom'         => $c->vehicule?->proprietaire
                ? trim(($c->vehicule->proprietaire->prenom ?? '') . ' ' . ($c->vehicule->proprietaire->nom ?? ''))
                : null,
            'montant_commande'         => (float) $c->montant_commande,
            'montant_commission_totale'=> (float) $c->montant_commission_totale,
            'montant_verse'            => (float) $c->montant_verse,
            'montant_restant'          => (float) $c->montant_restant,
            'statut'                   => $c->statut?->value,
            'statut_label'             => $c->statut_label,
            'is_versee'                => $c->isVersee(),
            'is_annulee'               => $c->isAnnulee(),
            'created_at'               => $c->created_at?->format('d/m/Y'),
            // Résumé des parts pour l'index
            'nb_parts'                 => $c->relationLoaded('parts') ? $c->parts->count() : null,
        ];

        if ($withParts) {
            $parts = $c->relationLoaded('parts') ? $c->parts : $c->load('parts.versements.creator')->parts;

            $data['parts'] = $parts->map(fn (CommissionPart $p) => [
                'id'                    => $p->id,
                'type_beneficiaire'     => $p->type_beneficiaire,
                'beneficiaire_nom'      => $p->beneficiaire_nom,
                'taux_commission'       => (float) $p->taux_commission,
                'montant_brut'          => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'montant_net'           => (float) $p->montant_net,
                'montant_verse'         => (float) $p->montant_verse,
                'montant_restant'       => (float) $p->montant_restant,
                'statut'                => $p->statut?->value,
                'statut_label'          => $p->statut_label,
                'is_versee'             => $p->isVersee(),
                'versements'            => $p->versements
                    ->sortByDesc(fn ($v) => $v->created_at?->timestamp ?? 0)
                    ->values()
                    ->map(fn ($v) => [
                        'id'            => $v->id,
                        'date_versement'=> $v->date_versement?->format('d/m/Y'),
                        'mode_paiement' => $v->mode_paiement instanceof \App\Enums\ModePaiement
                            ? $v->mode_paiement->label()
                            : (string) $v->mode_paiement,
                        'montant'       => (float) $v->montant,
                        'note'          => $v->note,
                        'created_by'    => $v->creator?->name,
                    ])
                    ->all(),
            ])->values()->all();
        }

        return $data;
    }
}
