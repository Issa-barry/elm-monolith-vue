<?php

namespace App\Services\Rapports;

use App\Enums\ModePaiement;
use App\Enums\NatureMouvementFonds;
use App\Enums\StatutDepense;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Services\Tresorerie\RemisesAgencesService;
use App\Support\Rapports\RapportPerimetre;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Remise d'un agent à SON agence de rattachement (ADR 0064, règle des charges du 15/11/2026) — niveau
 * agent seulement, jamais appliqué au rapport par agence (remise à la trésorerie principale, ADR 0016) :
 *
 *   net à remettre = encaissé (espèces + Mobile Money) + approvisionnements reçus − charges VALIDÉES
 *   reste à remettre = net à remettre − déjà remis (réception confirmée par l'agence)
 *
 * Situation CUMULÉE jusqu'à la fin de la période choisie, depuis la validation de la première caisse
 * dédiée ou du premier compte Mobile Money de l'agent (avant, ses encaissements n'étaient pas suivis
 * par des versements) : un report d'un jour sur l'autre n'est jamais perdu.
 *
 * - Encaissé : espèces et Mobile Money dont il est l'auteur — les fonds qu'il détient ; virement et
 *   chèque vont directement à la banque de l'agence.
 * - Charges validées : dépenses dont il est l'auteur, statut Validé (en attente, rejetées, annulées
 *   jamais déduites). Une seule déduction : la validation d'une dépense crédite la caisse de l'agence
 *   (571000), jamais le sous-compte de la caisse de l'agent — son solde n'en tient donc pas compte.
 * - Déjà remis : versements de caisse et transferts Mobile Money reçus par l'agence. Une remise
 *   envoyée mais non confirmée est « en transit » : elle reste comprise dans le reste à remettre.
 * - Remis sur la période : réceptions confirmées pendant la période (activité affichée, statut).
 */
class RemiseAgentService
{
    /**
     * Remise de chaque agent du périmètre ayant une caisse dédiée ou un compte Mobile Money validé.
     *
     * @return array<string, array<string, mixed>> par identifiant d'agent
     */
    public function parAgent(RapportPerimetre $p): array
    {
        if ($p->aucuneAgence()) {
            return [];
        }

        $supports = CompteTresorerie::query()
            ->forOrg($p->organizationId)
            ->whereNotNull('agent_id')
            ->whereNotNull('valide_le')
            ->where(fn ($q) => $q->caissesDediees()->orWhere(fn ($m) => $m->mobileMoneyDedies()))
            ->when($p->siteIds !== null, fn ($q) => $q->whereIn('site_id', $p->siteIds))
            ->when($p->agentId !== null, fn ($q) => $q->where('agent_id', $p->agentId))
            ->with('site:id,nom')
            ->get(['id', 'agent_id', 'site_id', 'valide_le', 'type', 'actif'])
            ->groupBy(fn (CompteTresorerie $c) => (string) $c->agent_id);

        if ($supports->isEmpty()) {
            return [];
        }

        $debut = $p->debut()->toDateString();
        $fin = $p->fin()->toDateString();
        $agentIds = $supports->keys()->all();
        $origines = $supports->map(fn (Collection $s) => CarbonImmutable::parse($s->min('valide_le'))->toDateString());

        $encaissements = $this->parJour($this->encaissements($p, $agentIds, $fin)->get(), $origines);
        $charges = $this->parJour($this->chargesValidees($p, $agentIds, $fin)->get(), $origines);

        $supportIds = $supports->flatten()->pluck('id')->all();
        $versements = MouvementFonds::query()
            ->where('organization_id', $p->organizationId)
            ->whereIn('nature', [NatureMouvementFonds::INTERNE_CAISSES->value, NatureMouvementFonds::TRANSFERT_MOBILE_MONEY->value])
            ->whereIn('compte_tresorerie_origine_id', $supportIds)
            ->whereNotIn('statut', [StatutMouvementFonds::BROUILLON->value, StatutMouvementFonds::ANNULE->value])
            ->whereNotNull('date_envoi')
            ->get(['compte_tresorerie_origine_id', 'montant', 'statut', 'date_envoi', 'date_reception']);
        $approvisionnements = MouvementFonds::query()
            ->where('organization_id', $p->organizationId)
            ->where('nature', NatureMouvementFonds::APPROVISIONNEMENT_CAISSE->value)
            ->whereIn('compte_tresorerie_destination_id', $supportIds)
            ->where('statut', StatutMouvementFonds::RECU->value)
            ->whereNotNull('date_reception')
            ->get(['compte_tresorerie_destination_id', 'montant', 'date_reception']);

        return $supports->map(function (Collection $supportsAgent, string $agentId) use ($origines, $encaissements, $charges, $versements, $approvisionnements, $debut, $fin) {
            $ids = $supportsAgent->pluck('id')->all();
            $jour = fn ($date) => $date === null ? null : CarbonImmutable::parse($date)->toDateString();
            $versementsAgent = $versements->filter(fn (MouvementFonds $m) => in_array($m->compte_tresorerie_origine_id, $ids, true)
                && $jour($m->date_envoi) <= $fin);
            $recuAvantFin = fn (MouvementFonds $m) => $m->statut === StatutMouvementFonds::RECU && $jour($m->date_reception) <= $fin;

            $encaisse = round((float) ($encaissements[$agentId] ?? 0.0), 2);
            $approvisionne = round((float) $approvisionnements
                ->filter(fn (MouvementFonds $m) => in_array($m->compte_tresorerie_destination_id, $ids, true)
                    && $jour($m->date_reception) >= $origines[$agentId] && $jour($m->date_reception) <= $fin)
                ->sum('montant'), 2);
            $chargesValidees = round((float) ($charges[$agentId] ?? 0.0), 2);
            $dejaRemis = round((float) $versementsAgent->filter($recuAvantFin)->sum('montant'), 2);
            // Envoyé avant la fin de la période et pas encore reçu à cette date (contesté compris).
            $enTransit = round((float) $versementsAgent
                ->filter(fn (MouvementFonds $m) => in_array($m->statut, [StatutMouvementFonds::ENVOYE, StatutMouvementFonds::CONTESTE], true)
                    || ($m->statut === StatutMouvementFonds::RECU && $jour($m->date_reception) > $fin))
                ->sum('montant'), 2);
            $remisPeriode = round((float) $versementsAgent
                ->filter(fn (MouvementFonds $m) => $recuAvantFin($m) && $jour($m->date_reception) >= $debut)
                ->sum('montant'), 2);

            $net = round($encaisse + $approvisionne - $chargesValidees, 2);
            $reste = round($net - $dejaRemis, 2);
            $statut = RemisesAgencesService::statut($reste, $enTransit, $remisPeriode);

            return [
                'depuis' => $origines[$agentId],
                'encaisse' => $encaisse,
                'approvisionnements' => $approvisionne,
                'charges_validees' => $chargesValidees,
                'net_a_remettre' => $net,
                'deja_remis' => $dejaRemis,
                'remis' => $remisPeriode,
                'en_transit' => $enTransit,
                'reste_a_remettre' => $reste,
                'agences' => $supportsAgent->pluck('site.nom')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
                'derniere_remise' => $versementsAgent->max(fn (MouvementFonds $m) => $jour($m->date_envoi)),
                'caisse_active' => $supportsAgent->contains(fn (CompteTresorerie $c) => (bool) $c->actif),
                'statut' => $statut,
                'statut_label' => RemisesAgencesService::LIBELLES[$statut],
            ];
        })
            // Une ancienne caisse inactive sans aucun montant n'a rien à montrer.
            ->filter(fn (array $r) => $r['caisse_active'] || abs($r['net_a_remettre']) >= 0.005 || abs($r['reste_a_remettre']) >= 0.005 || $r['en_transit'] > 0 || $r['remis'] > 0)
            ->all();
    }

    /**
     * Somme de plusieurs remises (vue agence, ligne Total) — les montants s'additionnent agent par
     * agent ; null si aucun agent n'a de caisse.
     *
     * @param  iterable<array<string, mixed>>  $remises
     * @return array<string, mixed>|null
     */
    public static function total(iterable $remises): ?array
    {
        $remises = collect($remises)->filter()->values();
        if ($remises->isEmpty()) {
            return null;
        }

        $somme = fn (string $champ) => round((float) $remises->sum($champ), 2);
        $reste = $somme('reste_a_remettre');
        $statut = RemisesAgencesService::statut($reste, $somme('en_transit'), $somme('remis'));

        return [
            'depuis' => $remises->min('depuis'),
            'encaisse' => $somme('encaisse'),
            'approvisionnements' => $somme('approvisionnements'),
            'charges_validees' => $somme('charges_validees'),
            'net_a_remettre' => $somme('net_a_remettre'),
            'deja_remis' => $somme('deja_remis'),
            'remis' => $somme('remis'),
            'en_transit' => $somme('en_transit'),
            'reste_a_remettre' => $reste,
            'agences' => $remises->flatMap(fn (array $r) => $r['agences'])->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'derniere_remise' => $remises->max('derniere_remise'),
            'caisse_active' => $remises->contains('caisse_active', true),
            'statut' => $statut,
            'statut_label' => RemisesAgencesService::LIBELLES[$statut],
        ];
    }

    /** Encaissements espèces et Mobile Money des agents jusqu'à la fin de la période, par jour. */
    private function encaissements(RapportPerimetre $p, array $agentIds, string $fin): Builder
    {
        return DB::table('encaissements_ventes as ev')
            ->join('factures_ventes as fv', 'fv.id', '=', 'ev.facture_vente_id')
            ->where('fv.organization_id', $p->organizationId)
            ->whereNull('fv.deleted_at')
            ->whereIn('ev.created_by', $agentIds)
            ->whereIn('ev.mode_paiement', [ModePaiement::ESPECES->value, ModePaiement::MOBILE_MONEY->value])
            // Axe trésorerie, comme le bloc Encaissements (ADR 0012).
            ->when($p->siteIds !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('ev.site_encaissement_id', $p->siteIds)
                ->orWhere(fn ($legacy) => $legacy->whereNull('ev.site_encaissement_id')->whereIn('fv.site_id', $p->siteIds))))
            ->where('ev.date_encaissement', '<', CarbonImmutable::parse($fin)->addDay()->toDateString())
            ->groupBy('ev.created_by', 'ev.date_encaissement')
            ->selectRaw('ev.created_by as agent_id, ev.date_encaissement as jour, COALESCE(SUM(ev.montant), 0) as montant');
    }

    /** Charges validées saisies par les agents jusqu'à la fin de la période, par jour. */
    private function chargesValidees(RapportPerimetre $p, array $agentIds, string $fin): Builder
    {
        return DB::table('depenses as d')
            ->where('d.organization_id', $p->organizationId)
            ->whereNull('d.deleted_at')
            ->whereIn('d.user_id', $agentIds)
            ->where('d.statut', StatutDepense::VALIDE->value)
            ->when($p->siteIds !== null, fn ($q) => $q->whereIn('d.site_id', $p->siteIds))
            ->where('d.date_depense', '<', CarbonImmutable::parse($fin)->addDay()->toDateString())
            ->groupBy('d.user_id', 'd.date_depense')
            ->selectRaw('d.user_id as agent_id, d.date_depense as jour, COALESCE(SUM(d.montant), 0) as montant');
    }

    /**
     * Total par agent des lignes datées à partir de son origine (validation de sa première caisse).
     *
     * @param  Collection<int, object>  $lignes
     * @param  Collection<string, string>  $origines
     * @return array<string, float>
     */
    private function parJour(Collection $lignes, Collection $origines): array
    {
        return $lignes
            ->filter(fn ($l) => CarbonImmutable::parse($l->jour)->toDateString() >= ($origines[(string) $l->agent_id] ?? '9999-12-31'))
            ->groupBy(fn ($l) => (string) $l->agent_id)
            ->map(fn (Collection $l) => round((float) $l->sum('montant'), 2))
            ->all();
    }
}
