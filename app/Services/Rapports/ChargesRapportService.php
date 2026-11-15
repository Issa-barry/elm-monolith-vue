<?php

namespace App\Services\Rapports;

use App\Enums\CategorieDepense;
use App\Enums\StatutDepense;
use App\Services\Comptabilite\DepenseComptabilisationService;
use App\Support\Rapports\RapportPerimetre;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Charges des rapports (module Dépenses, docs/rapports.md, ADR 0063 point 6 et ADR 0064) — un seul
 * calcul pour les trois écrans, sur deux axes qui ne se confondent jamais :
 *
 * - agence (`depenses.site_id`) : charges saisies DANS l'agence (rapport par agence) ;
 * - agent (`depenses.user_id`) : charges saisies PAR l'agent (rapport d'activité, Ma situation),
 *   limitées aux agences du périmètre.
 *
 * Période : `date_depense`, bornes semi-ouvertes (ADR 0057). Payées = VALIDÉES (la validation passe
 * la sortie de trésorerie, DepenseComptabilisationService : pas de « validée non payée ») ; en
 * attente = SOUMISES ; saisies = payées + en attente. Brouillons, rejetées, annulées et supprimées
 * exclues. Dont avances sur tiers = part des payées comptabilisée en avance récupérable.
 */
class ChargesRapportService
{
    public const LIMITE_LIGNES = 300;

    public const VIDES = [
        'charges_saisies' => 0.0,
        'charges_payees' => 0.0,
        'charges_payees_nombre' => 0,
        'charges_en_attente' => 0.0,
        'charges_en_attente_nombre' => 0,
        'charges_avances_tiers' => 0.0,
    ];

    /**
     * Charges saisies dans chaque agence.
     *
     * @param  list<string>  $siteIds
     * @return array<string, array<string, float|int>>
     */
    public function parSite(RapportPerimetre $p, array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }

        return $this->agregats($this->base($p)->whereIn('d.site_id', $siteIds), 'd.site_id');
    }

    /**
     * Charges saisies par chaque agent du périmètre (agences et agent filtrés).
     *
     * @return array<string, array<string, float|int>>
     */
    public function parAgent(RapportPerimetre $p): array
    {
        if ($p->aucuneAgence()) {
            return [];
        }

        return $this->agregats($this->duPerimetre($p)->whereNotNull('d.user_id'), 'd.user_id');
    }

    /**
     * Total du périmètre et liste des charges saisies (les plus récentes d'abord ; écran : lignes
     * limitées, export : $limite = null).
     *
     * @return array{resume: array<string, float|int>, lignes: list<array<string, mixed>>, total_lignes: int}
     */
    public function section(RapportPerimetre $p, ?int $limite = self::LIMITE_LIGNES): array
    {
        if ($p->aucuneAgence()) {
            return ['resume' => self::VIDES, 'lignes' => [], 'total_lignes' => 0];
        }

        $resume = $this->agregats($this->duPerimetre($p), null)['tout'] ?? self::VIDES;

        $lignes = $this->duPerimetre($p)
            ->leftJoin('sites as s', 's.id', '=', 'd.site_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('personnes as pa', 'pa.id', '=', 'u.personne_id')
            ->select([
                'd.id', 'd.date_depense', 'd.montant', 'd.statut', 'd.commentaire',
                't.libelle as type_libelle', 't.categorie',
                's.nom as site_nom', 'pa.prenom as agent_prenom', 'pa.nom as agent_nom',
            ])
            ->orderByDesc('d.date_depense')
            ->orderByDesc('d.created_at')
            ->when($limite !== null, fn (Builder $q) => $q->limit($limite))
            ->get()
            ->map(function ($l) {
                $statut = StatutDepense::tryFrom((string) $l->statut);
                $categorie = CategorieDepense::tryFrom((string) $l->categorie);

                return [
                    'id' => $l->id,
                    'date' => CarbonImmutable::parse($l->date_depense)->toDateString(),
                    'type' => $l->type_libelle,
                    'categorie' => $categorie?->label(),
                    'avance_tiers' => $categorie !== null && in_array($categorie, DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS, true),
                    'commentaire' => $l->commentaire,
                    'site_nom' => $l->site_nom,
                    'agent' => trim(($l->agent_prenom ?? '').' '.($l->agent_nom ?? '')) ?: null,
                    'montant' => round((float) $l->montant, 2),
                    'statut' => $statut?->value ?? (string) $l->statut,
                    'statut_label' => $statut?->label() ?? (string) $l->statut,
                ];
            });

        return [
            'resume' => $resume,
            'lignes' => $lignes->all(),
            'total_lignes' => (int) $resume['charges_payees_nombre'] + (int) $resume['charges_en_attente_nombre'],
        ];
    }

    /** Charges validées ou soumises de l'organisation sur la période. */
    private function base(RapportPerimetre $p): Builder
    {
        return DB::table('depenses as d')
            ->leftJoin('depense_types as t', 't.id', '=', 'd.depense_type_id')
            ->where('d.organization_id', $p->organizationId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.statut', [StatutDepense::VALIDE->value, StatutDepense::SOUMIS->value])
            // Bornes semi-ouvertes (ADR 0057, comme Periode::appliquer) : sûres quel que soit le
            // stockage de la colonne date (SQLite y ajoute 00:00:00).
            ->where('d.date_depense', '>=', $p->debut()->toDateString())
            ->where('d.date_depense', '<', $p->fin()->startOfDay()->addDay()->toDateString());
    }

    /** Axe agent : charges saisies par les agents, dans les agences du périmètre. */
    private function duPerimetre(RapportPerimetre $p): Builder
    {
        return $this->base($p)
            ->when($p->siteIds !== null, fn (Builder $q) => $q->whereIn('d.site_id', $p->siteIds))
            ->when($p->agentId !== null, fn (Builder $q) => $q->where('d.user_id', $p->agentId));
    }

    /**
     * @param  ?string  $groupe  colonne de regroupement, ou null pour un total (clé « tout »)
     * @return array<string, array<string, float|int>>
     */
    private function agregats(Builder $q, ?string $groupe): array
    {
        $valide = StatutDepense::VALIDE->value;
        $soumis = StatutDepense::SOUMIS->value;
        $avances = array_map(fn (CategorieDepense $c) => $c->value, DepenseComptabilisationService::CATEGORIES_AVANCE_TIERS);
        $marques = implode(', ', array_fill(0, count($avances), '?'));

        return $q
            ->when($groupe !== null, fn (Builder $b) => $b->groupBy($groupe)->select("{$groupe} as cle"))
            ->when($groupe === null, fn (Builder $b) => $b->selectRaw("'tout' as cle"))
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN d.montant ELSE 0 END), 0) as payees', [$valide])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN 1 ELSE 0 END), 0) as payees_nombre', [$valide])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN d.montant ELSE 0 END), 0) as en_attente', [$soumis])
            ->selectRaw('COALESCE(SUM(CASE WHEN d.statut = ? THEN 1 ELSE 0 END), 0) as en_attente_nombre', [$soumis])
            ->selectRaw("COALESCE(SUM(CASE WHEN d.statut = ? AND t.categorie IN ({$marques}) THEN d.montant ELSE 0 END), 0) as avances", [$valide, ...$avances])
            ->get()
            ->mapWithKeys(fn ($l) => [(string) $l->cle => [
                'charges_saisies' => round((float) $l->payees + (float) $l->en_attente, 2),
                'charges_payees' => round((float) $l->payees, 2),
                'charges_payees_nombre' => (int) $l->payees_nombre,
                'charges_en_attente' => round((float) $l->en_attente, 2),
                'charges_en_attente_nombre' => (int) $l->en_attente_nombre,
                'charges_avances_tiers' => round((float) $l->avances, 2),
            ]])
            ->all();
    }
}
