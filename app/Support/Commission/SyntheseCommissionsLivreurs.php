<?php

namespace App\Support\Commission;

use App\Enums\StatutCommission;
use App\Models\CommissionEnveloppePart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Ce que l'écran Commissions des livreurs lit sur les parts de chaque livreur (véhicules, agences,
 * processus, nombre de commandes, première échéance impayée, dates des commissions), obtenu en
 * lisant des lignes légères au lieu de charger chaque part avec sa commission, sa source et son
 * véhicule. Sur tout l'historique (des dizaines de milliers de parts), ce chargement faisait
 * l'essentiel du temps de la page.
 *
 * Mêmes règles que les relations des modèles :
 * - véhicule = véhicule (non supprimé) de la source, vente ou transfert ;
 * - agence = CommissionEnveloppe::siteResponsableId() : site du véhicule, sinon site de la vente
 *   (ou site de départ du transfert, CommissionLogistiqueService::resolveSiteResponsable()) ;
 *   une source supprimée n'a ni véhicule ni agence ;
 * - ordre des livreurs et des véhicules : ordre d'apparition des parts, comme avant.
 */
final class SyntheseCommissionsLivreurs
{
    /**
     * @param  Builder<CommissionEnveloppePart>  $parts  requête filtrée des parts
     * @return array<string, array{nb_commandes: int, premiere_echeance: ?Carbon, a_une_part_payable: bool, processus_codes: list<string>, vehicule_ids: list<string>, site_ids: list<string>, dates: list<string>}>
     */
    public static function parLivreur(Builder $parts): array
    {
        $base = (clone $parts)->setEagerLoads([])->reorder();
        $lignes = (clone $base)->toBase()
            ->select('commission_enveloppe_parts.beneficiaire_id', 'commission_enveloppe_parts.enveloppe_id', 'commission_enveloppe_parts.statut')
            ->get();
        if ($lignes->isEmpty()) {
            return [];
        }

        $enveloppes = LecturePartsCommission::enveloppes(LecturePartsCommission::enveloppeIds($base));
        $aEcheance = [StatutCommission::IMPAYE->value, StatutCommission::PARTIEL->value];
        $creee = StatutCommission::CREEE->value;

        $brut = [];
        foreach ($lignes as $ligne) {
            $id = (string) $ligne->beneficiaire_id;
            $brut[$id] ??= ['enveloppes' => [], 'premiere' => null, 'payable' => false, 'codes' => [], 'vehicules' => [], 'sites' => [], 'dates' => []];
            $r = &$brut[$id];
            $r['enveloppes'][$ligne->enveloppe_id] = true;
            $r['payable'] = $r['payable'] || $ligne->statut !== $creee;

            $e = $enveloppes[$ligne->enveloppe_id] ?? null;
            if ($e !== null) {
                if ($e->earned_at !== null) {
                    $r['dates'][] = $e->earned_at;
                    if (in_array($ligne->statut, $aEcheance, true) && ($r['premiere'] === null || $e->earned_at < $r['premiere'])) {
                        $r['premiere'] = $e->earned_at;
                    }
                }
                if ($e->code !== null) {
                    $r['codes'][$e->code] = true;
                }
                if ($e->vehicule_id !== null) {
                    $r['vehicules'][(string) $e->vehicule_id] = true;
                }
                if ($e->site_id !== null) {
                    $r['sites'][(string) $e->site_id] = true;
                }
            }
            unset($r);
        }

        return array_map(fn (array $r) => [
            'nb_commandes' => count($r['enveloppes']),
            'premiere_echeance' => $r['premiere'] !== null ? Carbon::parse($r['premiere'])->startOfDay() : null,
            'a_une_part_payable' => $r['payable'],
            'processus_codes' => array_keys($r['codes']),
            'vehicule_ids' => array_keys($r['vehicules']),
            'site_ids' => array_keys($r['sites']),
            'dates' => $r['dates'],
        ], $brut);
    }
}
