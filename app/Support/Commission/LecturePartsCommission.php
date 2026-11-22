<?php

namespace App\Support\Commission;

use App\Models\CommandeVente;
use App\Models\CommissionEnveloppePart;
use App\Models\TransfertLogistique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Lecture « légère » des parts de commission pour les écrans d'index (livreurs, propriétaires,
 * sites) : au lieu de charger chaque part avec sa commission, sa vente ou son transfert, son
 * véhicule, son site et ses lignes (des dizaines de milliers de modèles sur tout l'historique,
 * l'essentiel du temps de ces pages), on lit les seules colonnes utiles.
 *
 * Mêmes règles que les modèles et leurs relations :
 * - montant_a_payer = montant_actuel s'il est renseigné, sinon montant_net ; montants arrondis au
 *   centime comme le cast decimal:2 ;
 * - une source (vente ou transfert) supprimée n'a ni véhicule, ni site, ni agence ;
 * - véhicule = véhicule non supprimé de la source ;
 * - agence (site responsable) = CommissionEnveloppe::siteResponsableId() : site du véhicule, sinon
 *   site de la vente, ou site de départ du transfert ;
 * - site de la source = site non supprimé de la vente, ou site de départ du transfert.
 */
final class LecturePartsCommission
{
    /**
     * Parts de la requête, dans l'ordre où la requête les renvoie (le même que ->get()).
     *
     * @param  Builder<CommissionEnveloppePart>  $parts
     * @return list<object{beneficiaire_id: string, enveloppe_id: string, statut: string, brut: float, a_payer: float, verse: float}>
     */
    public static function lignes(Builder $parts): array
    {
        $lignes = [];
        $base = (clone $parts)->setEagerLoads([])->toBase()->select(
            'commission_enveloppe_parts.beneficiaire_id',
            'commission_enveloppe_parts.enveloppe_id',
            'commission_enveloppe_parts.statut',
            'commission_enveloppe_parts.montant_brut',
            'commission_enveloppe_parts.montant_net',
            'commission_enveloppe_parts.montant_actuel',
            'commission_enveloppe_parts.montant_verse',
        );
        foreach ($base->get() as $l) {
            $lignes[] = (object) [
                'beneficiaire_id' => (string) $l->beneficiaire_id,
                'enveloppe_id' => (string) $l->enveloppe_id,
                'statut' => (string) $l->statut,
                'brut' => round((float) $l->montant_brut, 2),
                'a_payer' => round((float) ($l->montant_actuel ?? $l->montant_net), 2),
                'verse' => round((float) $l->montant_verse, 2),
            ];
        }

        return $lignes;
    }

    /**
     * Commission de chaque part : date, processus, véhicule, agence et site de sa source.
     *
     * @param  QueryBuilder  $enveloppeIds  sous-requête des identifiants de commissions
     * @return array<string, object{earned_at: ?string, code: ?string, vehicule_id: ?string, vehicule_nom: ?string, vehicule_immatriculation: ?string, site_id: ?string, source_site_id: ?string, source_site_nom: ?string}>
     */
    public static function enveloppes(QueryBuilder $enveloppeIds): array
    {
        return DB::table('commission_enveloppes as e')
            ->leftJoin('commission_processus as pr', 'pr.id', '=', 'e.processus_id')
            ->leftJoin('commandes_ventes as cv', fn ($j) => $j->on('cv.id', '=', 'e.source_id')
                ->where('e.source_type', '=', CommandeVente::class)
                ->whereNull('cv.deleted_at'))
            ->leftJoin('transferts_logistiques as t', fn ($j) => $j->on('t.id', '=', 'e.source_id')
                ->where('e.source_type', '=', TransfertLogistique::class)
                ->whereNull('t.deleted_at'))
            ->leftJoin('vehicules as v', fn ($j) => $j->on('v.id', '=', DB::raw('COALESCE(cv.vehicule_id, t.vehicule_id)'))
                ->whereNull('v.deleted_at'))
            ->leftJoin('sites as ss', fn ($j) => $j->on('ss.id', '=', DB::raw('COALESCE(cv.site_id, t.site_source_id)'))
                ->whereNull('ss.deleted_at'))
            ->whereIn('e.id', $enveloppeIds)
            ->select('e.id', 'e.earned_at', 'pr.code', 'v.id as vehicule_id', 'v.nom_vehicule as vehicule_nom', 'v.immatriculation as vehicule_immatriculation',
                'ss.id as source_site_id', 'ss.nom as source_site_nom')
            ->selectRaw('CASE WHEN cv.id IS NOT NULL THEN COALESCE(v.site_id, cv.site_id) WHEN t.id IS NOT NULL THEN COALESCE(v.site_id, t.site_source_id) END as site_id')
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Identifiants des commissions des parts de la requête, en sous-requête.
     *
     * @param  Builder<CommissionEnveloppePart>  $parts
     */
    public static function enveloppeIds(Builder $parts): QueryBuilder
    {
        return (clone $parts)->setEagerLoads([])->toBase()->select('commission_enveloppe_parts.enveloppe_id');
    }
}
