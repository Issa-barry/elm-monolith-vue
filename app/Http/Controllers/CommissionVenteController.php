<?php

namespace App\Http\Controllers;

use App\Enums\ModePaiement;
use App\Enums\StatutCommission;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Livreur;
use App\Models\Proprietaire;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionVenteController extends Controller
{
    private const DATE_DISPLAY_FORMAT = 'd/m/Y';

    // ── Index : Grand Livre par bénéficiaire ─────────────────────────────────

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        $orgId          = auth()->user()->organization_id;
        $tab            = $request->input('tab', 'livreurs'); // livreurs | proprietaires
        $periodeDefault = $tab === 'proprietaires' ? 'month' : 'week';
        $periode        = $request->input('periode', $periodeDefault);
        $typeBeneficiaire = $tab === 'proprietaires' ? 'proprietaire' : 'livreur';

        // ── Requête agrégée par bénéficiaire ──────────────────────────────────
        $query = CommissionPart::query()
            ->from('commission_parts AS cp')
            ->join('commissions_ventes AS cv', 'cv.id', '=', 'cp.commission_vente_id')
            ->where('cv.organization_id', $orgId)
            ->where('cp.type_beneficiaire', $typeBeneficiaire)
            ->where('cp.statut', '!=', StatutCommission::ANNULEE->value);

        // Filtre période sur la date de la commission parent
        match ($periode) {
            'today' => $query->whereDate('cv.created_at', now()),
            'week'  => $query->whereBetween('cv.created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereYear('cv.created_at', now()->year)
                             ->whereMonth('cv.created_at', now()->month),
            default => null,
        };

        if ($tab === 'livreurs') {
            $query
                ->whereNotNull('cp.livreur_id')
                ->leftJoin('livreurs', 'livreurs.id', '=', 'cp.livreur_id')
                ->select(['cp.livreur_id AS beneficiaire_id'])
                ->selectRaw(
                    '"livreur"                        AS type_beneficiaire,
                     MAX(cp.beneficiaire_nom)         AS beneficiaire_nom,
                     MAX(livreurs.telephone)          AS telephone,
                     SUM(cp.montant_brut)             AS total_brut_cumule,
                     SUM(cp.frais_supplementaires)    AS total_frais,
                     SUM(cp.montant_net)              AS total_net_cumule,
                     SUM(cp.montant_verse)            AS total_verse,
                     COUNT(DISTINCT cp.commission_vente_id) AS nb_commandes,
                     MAX(cv.created_at)               AS date_derniere_commande'
                )
                ->groupBy('cp.livreur_id');
        } else {
            $query
                ->whereNotNull('cp.proprietaire_id')
                ->leftJoin('proprietaires', 'proprietaires.id', '=', 'cp.proprietaire_id')
                ->select(['cp.proprietaire_id AS beneficiaire_id'])
                ->selectRaw(
                    '"proprietaire"                   AS type_beneficiaire,
                     MAX(cp.beneficiaire_nom)         AS beneficiaire_nom,
                     MAX(proprietaires.telephone)     AS telephone,
                     SUM(cp.montant_brut)             AS total_brut_cumule,
                     SUM(cp.frais_supplementaires)    AS total_frais,
                     SUM(cp.montant_net)              AS total_net_cumule,
                     SUM(cp.montant_verse)            AS total_verse,
                     COUNT(DISTINCT cp.commission_vente_id) AS nb_commandes,
                     MAX(cv.created_at)               AS date_derniere_commande'
                )
                ->groupBy('cp.proprietaire_id');
        }

        $rows = $query->orderByRaw('SUM(cp.montant_net) - SUM(cp.montant_verse) DESC')->get();

        // ── Mapping + statut_global + solde ───────────────────────────────────
        $beneficiaires = $rows->map(function ($row) {
            $totalNet   = (float) $row->total_net_cumule;
            $totalVerse = (float) $row->total_verse;
            $solde      = max(0.0, $totalNet - $totalVerse);

            $statutGlobal = match (true) {
                $totalNet > 0 && $totalVerse >= $totalNet => 'versee',
                $totalVerse > 0                           => 'partielle',
                default                                   => 'en_attente',
            };

            return [
                'beneficiaire_id'        => (int) $row->beneficiaire_id,
                'type_beneficiaire'      => $row->type_beneficiaire,
                'beneficiaire_nom'       => $row->beneficiaire_nom ?? '—',
                'telephone'              => $row->telephone,
                'total_brut_cumule'      => (float) $row->total_brut_cumule,
                'total_frais'            => (float) $row->total_frais,
                'total_net_cumule'       => $totalNet,
                'total_verse'            => $totalVerse,
                'solde_restant'          => $solde,
                'nb_commandes'           => (int) $row->nb_commandes,
                'date_derniere_commande' => $row->date_derniere_commande
                    ? \Carbon\Carbon::parse($row->date_derniere_commande)->format(self::DATE_DISPLAY_FORMAT)
                    : null,
                'statut_global'          => $statutGlobal,
            ];
        });

        // ── Filtres complémentaires (post-agrégation) ─────────────────────────
        $filtreStatut = $request->input('statut', '');
        $search       = $request->input('search', '');

        if ($filtreStatut) {
            $beneficiaires = $beneficiaires->filter(
                fn ($b) => $b['statut_global'] === $filtreStatut
            );
        }

        if ($search) {
            $q = mb_strtolower($search);
            $beneficiaires = $beneficiaires->filter(
                fn ($b) =>
                    str_contains(mb_strtolower($b['beneficiaire_nom']), $q) ||
                    ($b['telephone'] && str_contains($b['telephone'], $q))
            );
        }

        $list = $beneficiaires->values();

        $totaux = [
            'nb_beneficiaires' => $list->count(),
            'total_brut'       => (float) $list->sum('total_brut_cumule'),
            'total_verse'      => (float) $list->sum('total_verse'),
            'solde_total'      => (float) $list->sum('solde_restant'),
            'nb_en_attente'    => $list->where('statut_global', 'en_attente')->count(),
            'nb_partielle'     => $list->where('statut_global', 'partielle')->count(),
            'nb_versee'        => $list->where('statut_global', 'versee')->count(),
        ];

        return Inertia::render('Commissions/Index', [
            'beneficiaires' => $list,
            'totaux'        => $totaux,
            'periode'       => $periode,
            'tab'           => $tab,
            'filtre_statut' => $filtreStatut,
            'search'        => $search,
        ]);
    }

    // ── Détail bénéficiaire ───────────────────────────────────────────────────

    /**
     * GET /commissions/beneficiaires/{type}/{beneficiaireId}
     */
    public function showBeneficiaire(Request $request, string $type, int $beneficiaireId): Response
    {
        $this->authorize('viewAny', \App\Models\CommandeVente::class);

        abort_unless(in_array($type, ['livreur', 'proprietaire'], true), 422);

        $orgId = auth()->user()->organization_id;

        // Infos du bénéficiaire
        if ($type === 'livreur') {
            $model     = Livreur::find($beneficiaireId);
            $nom       = $model ? trim("{$model->prenom} {$model->nom}") : '—';
            $telephone = $model?->telephone;
        } else {
            $model     = Proprietaire::find($beneficiaireId);
            $nom       = $model ? trim(($model->prenom ?? '').' '.($model->nom ?? '')) : '—';
            $telephone = $model?->telephone;
        }

        // Parts de ce bénéficiaire sur toutes les commissions de l'org
        $query = CommissionPart::with([
            'commission.commande.site',
            'commission.vehicule',
            'versements.creator',
        ])
        ->whereHas('commission', fn ($q) => $q->where('organization_id', $orgId))
        ->where('type_beneficiaire', $type)
        ->where('statut', '!=', StatutCommission::ANNULEE->value);

        if ($type === 'livreur') {
            $query->where('livreur_id', $beneficiaireId);
        } else {
            $query->where('proprietaire_id', $beneficiaireId);
        }

        $parts = $query->orderByDesc('commission_vente_id')->get();

        // ── Résumé cumulé ─────────────────────────────────────────────────────
        $totalNet   = (float) $parts->sum('montant_net');
        $totalVerse = (float) $parts->sum('montant_verse');
        $solde      = max(0.0, $totalNet - $totalVerse);

        // ── Calcul disponibilité (règle de paiement par type) ─────────────────
        // Livreur      : disponible après earned_at (commission.created_at) + 14 jours
        // Propriétaire : disponible le 1er du mois suivant earned_at
        $totalDisponible = 0.0;
        foreach ($parts as $p) {
            $restantPart = max(0.0, (float) $p->montant_net - (float) $p->montant_verse);
            if ($restantPart <= 0) {
                continue;
            }
            $earnedAt = $p->commission?->created_at;
            $disponibleAt = match ($type) {
                'livreur'      => $earnedAt?->clone()->addDays(14),
                'proprietaire' => $earnedAt?->clone()->addMonthNoOverflow()->startOfMonth(),
                default        => null,
            };
            if (!$disponibleAt || now()->greaterThanOrEqualTo($disponibleAt)) {
                $totalDisponible += $restantPart;
            }
        }
        $totalEnAttente = max(0.0, $solde - $totalDisponible);

        $resume = [
            'id'              => $beneficiaireId,
            'type'            => $type,
            'nom'             => $nom,
            'telephone'       => $telephone,
            'nb_commandes'    => $parts->pluck('commission_vente_id')->unique()->count(),
            'total_brut'      => (float) $parts->sum('montant_brut'),
            'total_frais'     => (float) $parts->sum('frais_supplementaires'),
            'total_net'       => $totalNet,
            'total_verse'     => $totalVerse,
            'solde_restant'   => $solde,
            'total_disponible'=> $totalDisponible,
            'total_en_attente'=> $totalEnAttente,
        ];

        // ── Commandes (une ligne par CommissionVente) ─────────────────────────
        $commandes = $parts
            ->groupBy('commission_vente_id')
            ->map(function ($partsGroup) use ($type) {
                $first      = $partsGroup->first();
                $commission = $first->commission;

                $montantBrut  = (float) $partsGroup->sum('montant_brut');
                $frais        = (float) $partsGroup->sum('frais_supplementaires');
                $montantNet   = (float) $partsGroup->sum('montant_net');
                $montantVerse = (float) $partsGroup->sum('montant_verse');
                $restant      = max(0.0, $montantNet - $montantVerse);

                // Disponibilité par commande
                $earnedAt = $commission->created_at;
                $disponibleAt = match ($type) {
                    'livreur'      => $earnedAt?->clone()->addDays(14),
                    'proprietaire' => $earnedAt?->clone()->addMonthNoOverflow()->startOfMonth(),
                    default        => null,
                };
                $isDisponible     = !$disponibleAt || now()->greaterThanOrEqualTo($disponibleAt);
                $montantDisponible = $isDisponible ? $restant : 0.0;
                $montantEnAttente  = $restant - $montantDisponible;

                $versements = $partsGroup
                    ->flatMap(fn ($p) => $p->versements)
                    ->sortByDesc(fn ($v) => $v->created_at?->timestamp ?? 0)
                    ->values()
                    ->map(fn ($v) => [
                        'id'             => $v->id,
                        'part_id'        => $v->commission_part_id,
                        'commission_id'  => $commission->id,
                        'date_versement' => $v->date_versement?->format(self::DATE_DISPLAY_FORMAT),
                        'enregistre_le'  => $v->created_at?->format('d/m/Y H:i'),
                        'montant'        => (float) $v->montant,
                        'mode_paiement'  => $v->mode_paiement instanceof ModePaiement
                            ? $v->mode_paiement->label()
                            : (string) $v->mode_paiement,
                        'note'           => $v->note,
                        'created_by'     => $v->creator?->name,
                    ])
                    ->all();

                return [
                    'commission_id'      => $commission->id,
                    'commande_reference' => $commission->commande?->reference,
                    'commande_id'        => $commission->commande_vente_id,
                    'date'               => $commission->created_at?->format(self::DATE_DISPLAY_FORMAT),
                    'vehicule'           => $commission->vehicule?->nom_vehicule,
                    'immatriculation'    => $commission->vehicule?->immatriculation,
                    'site'               => $commission->commande?->site?->nom,
                    'taux'               => (float) $first->taux_commission,
                    'montant_brut'       => $montantBrut,
                    'frais'              => $frais,
                    'montant_net'        => $montantNet,
                    'montant_verse'      => $montantVerse,
                    'restant'            => $restant,
                    'statut'             => $first->statut?->value,
                    'statut_label'       => $first->statut_label,
                    'part_id'            => $first->id,
                    'versements'         => $versements,
                    'disponible_le'      => $disponibleAt?->format(self::DATE_DISPLAY_FORMAT),
                    'montant_disponible' => $montantDisponible,
                    'montant_en_attente' => $montantEnAttente,
                ];
            })
            ->values();

        return Inertia::render('Commissions/Beneficiaire/Show', [
            'resume'         => $resume,
            'commandes'      => $commandes,
            'modes_paiement' => ModePaiement::options(),
        ]);
    }

    // ── Détail commission (conservé) ─────────────────────────────────────────

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
            'vehicule.frais',
            'parts.versements.creator',
            'parts.proprietaire:id,telephone',
            'parts.livreur:id,telephone',
        ]);

        return Inertia::render('Commissions/Show', [
            'commission'     => $this->mapCommission($commission_vente, withParts: true),
            'modes_paiement' => ModePaiement::options(),
        ]);
    }

    // ── Mapping commission (pour le détail existant) ──────────────────────────

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
            'vehicule_frais_total'      => $c->vehicule?->relationLoaded('frais')
                ? (float) $c->vehicule->frais->sum('montant')
                : 0.0,
            'montant_commande'          => (float) $c->montant_commande,
            'montant_commission_totale' => (float) $c->montant_commission_totale,
            'montant_verse'             => (float) $c->montant_verse,
            'montant_restant'           => (float) $c->montant_restant,
            'statut'                    => $c->statut?->value,
            'statut_label'              => $c->statut_label,
            'is_versee'                 => $c->isVersee(),
            'is_annulee'                => $c->isAnnulee(),
            'created_at'                => $c->created_at?->format(self::DATE_DISPLAY_FORMAT),
            'nb_parts'                  => $c->relationLoaded('parts') ? $c->parts->count() : null,
        ];

        if ($withParts) {
            $parts = $c->relationLoaded('parts') ? $c->parts : $c->load('parts.versements.creator')->parts;

            $data['parts'] = $parts->map(fn (CommissionPart $p) => [
                'id'                     => $p->id,
                'type_beneficiaire'      => $p->type_beneficiaire,
                'beneficiaire_nom'       => $p->beneficiaire_nom,
                'beneficiaire_telephone' => $p->proprietaire?->telephone ?? $p->livreur?->telephone,
                'role'                   => $p->role,
                'taux_commission'        => (float) $p->taux_commission,
                'montant_brut'           => (float) $p->montant_brut,
                'frais_supplementaires'  => (float) $p->frais_supplementaires,
                'type_frais'             => $p->type_frais,
                'commentaire_frais'      => $p->commentaire_frais,
                'montant_net'            => (float) $p->montant_net,
                'montant_verse'          => (float) $p->montant_verse,
                'montant_restant'        => (float) $p->montant_restant,
                'statut'                 => $p->statut?->value,
                'statut_label'           => $p->statut_label,
                'is_versee'              => $p->isVersee(),
                'versements'             => $p->versements
                    ->sortByDesc(fn ($v) => $v->created_at?->timestamp ?? 0)
                    ->values()
                    ->map(fn ($v) => [
                        'id'             => $v->id,
                        'date_versement' => $v->date_versement?->format(self::DATE_DISPLAY_FORMAT),
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
