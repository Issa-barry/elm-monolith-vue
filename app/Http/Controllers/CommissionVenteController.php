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
    // ── Index : liste des parts par onglet ───────────────────────────────────

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        $orgId = auth()->user()->organization_id;
        $tab   = $request->input('tab', 'livreurs'); // livreurs | proprietaires

        // Période par défaut selon l'onglet
        $periodeDefault = $tab === 'proprietaires' ? 'month' : 'week';
        $periode        = $request->input('periode', $periodeDefault);

        $typeBeneficiaire = $tab === 'proprietaires' ? 'proprietaire' : 'livreur';

        $query = CommissionPart::with([
            'commission' => fn ($q) => $q->with([
                'commande.site',
                'vehicule.equipe.membres' => fn ($mq) => $mq
                    ->where('role', 'principal')
                    ->with('livreur:id,telephone'),
            ]),
        ])
        ->whereHas('commission', function ($q) use ($orgId, $periode) {
            $q->where('organization_id', $orgId);
            match ($periode) {
                'today' => $q->whereDate('created_at', now()),
                'week'  => $q->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
                'month' => $q->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month),
                default => null,
            };
        })
        ->where('type_beneficiaire', $typeBeneficiaire)
        ->orderByDesc('commission_vente_id');

        $parts = $query->get()->map(fn (CommissionPart $p) => $this->mapPart($p));

        $actives = $parts->whereNotIn('statut', [StatutCommission::ANNULEE->value]);

        $totaux = [
            'total_commission'   => $actives->sum('montant_brut'),
            'total_a_verser'     => $actives->whereNotIn('statut', [StatutCommission::VERSEE->value])->sum('montant_restant'),
            'nb_en_attente'      => $parts->where('statut', StatutCommission::EN_ATTENTE->value)->count(),
            'montant_en_attente' => $parts->where('statut', StatutCommission::EN_ATTENTE->value)->sum('montant_net'),
            'nb_partielles'      => $parts->where('statut', StatutCommission::PARTIELLE->value)->count(),
            'montant_partielles' => $parts->where('statut', StatutCommission::PARTIELLE->value)->sum('montant_restant'),
            'nb_versees'         => $parts->where('statut', StatutCommission::VERSEE->value)->count(),
            'montant_versees'    => $parts->where('statut', StatutCommission::VERSEE->value)->sum('montant_verse'),
        ];

        return Inertia::render('Commissions/Index', [
            'parts'   => $parts->values(),
            'totaux'  => $totaux,
            'periode' => $periode,
            'tab'     => $tab,
        ]);
    }

    // ── Détail ───────────────────────────────────────────────────────────────

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
            'parts.proprietaire:id,telephone',
            'parts.livreur:id,telephone',
        ]);

        return Inertia::render('Commissions/Show', [
            'commission'     => $this->mapCommission($commission_vente, withParts: true),
            'modes_paiement' => ModePaiement::options(),
        ]);
    }

    // ── Mapping part (pour l'index) ───────────────────────────────────────────

    private function mapPart(CommissionPart $p): array
    {
        $commission = $p->commission;
        $principalTelephone = $commission->vehicule?->equipe?->membres?->first()?->livreur?->telephone;

        return [
            'id'                    => $p->id,
            'commission_id'         => $commission->id,
            'commande_id'           => $commission->commande_vente_id,
            'commande_reference'    => $commission->commande?->reference,
            'site_nom'              => $commission->commande?->site?->nom,
            'vehicule_nom'          => $commission->vehicule?->nom_vehicule,
            'immatriculation'       => $commission->vehicule?->immatriculation,
            'equipe_nom'            => $commission->vehicule?->equipe?->nom,
            'livreur_principal_telephone' => $principalTelephone,
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
            'created_at'            => $commission->created_at?->format('d/m/Y'),
        ];
    }

    // ── Mapping commission (pour le détail) ───────────────────────────────────

    private function mapCommission(CommissionVente $c, bool $withParts = false): array
    {
        $data = [
            'id'                        => $c->id,
            'commande_id'               => $c->commande_vente_id,
            'commande_reference'        => $c->commande?->reference,
            'site_nom'                  => $c->commande?->site?->nom,
            'vehicule_nom'              => $c->vehicule?->nom_vehicule,
            'immatriculation'           => $c->vehicule?->immatriculation,
            'equipe_nom'                => $c->vehicule?->equipe?->nom,
            'proprietaire_nom'          => $c->vehicule?->proprietaire
                ? trim(($c->vehicule->proprietaire->prenom ?? '').' '.($c->vehicule->proprietaire->nom ?? ''))
                : null,
            'montant_commande'          => (float) $c->montant_commande,
            'montant_commission_totale' => (float) $c->montant_commission_totale,
            'montant_verse'             => (float) $c->montant_verse,
            'montant_restant'           => (float) $c->montant_restant,
            'statut'                    => $c->statut?->value,
            'statut_label'              => $c->statut_label,
            'is_versee'                 => $c->isVersee(),
            'is_annulee'                => $c->isAnnulee(),
            'created_at'                => $c->created_at?->format('d/m/Y'),
            'nb_parts'                  => $c->relationLoaded('parts') ? $c->parts->count() : null,
        ];

        if ($withParts) {
            $parts = $c->relationLoaded('parts') ? $c->parts : $c->load('parts.versements.creator')->parts;

            $data['parts'] = $parts->map(fn (CommissionPart $p) => [
                'id'                    => $p->id,
                'type_beneficiaire'     => $p->type_beneficiaire,
                'beneficiaire_nom'      => $p->beneficiaire_nom,
                'beneficiaire_telephone'=> $p->proprietaire?->telephone ?? $p->livreur?->telephone,
                'role'                  => $p->role,
                'taux_commission'       => (float) $p->taux_commission,
                'montant_brut'          => (float) $p->montant_brut,
                'frais_supplementaires' => (float) $p->frais_supplementaires,
                'type_frais'            => $p->type_frais,
                'commentaire_frais'     => $p->commentaire_frais,
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
                        'id'             => $v->id,
                        'date_versement' => $v->date_versement?->format('d/m/Y'),
                        'enregistre_le'  => $v->created_at?->format('d/m/Y H:i'),
                        'mode_paiement'  => $v->mode_paiement instanceof ModePaiement
                            ? $v->mode_paiement->label()
                            : (string) $v->mode_paiement,
                        'montant'        => (float) $v->montant,
                        'note'           => $v->note,
                        'created_by'     => $v->creator?->name,
                    ])
                    ->all(),
            ])->values()->all();
        }

        return $data;
    }
}
