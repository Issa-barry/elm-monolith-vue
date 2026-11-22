<?php

namespace App\Support\Commission;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\Depense;
use App\Services\PeriodeComptableService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Vue par défaut de l'écran Commissions des livreurs et de ses exports (décision produit du
 * 22/11/2026, « option B ») : la quinzaine en cours, plus, pour chaque livreur, ses quinzaines
 * antérieures non soldées. « Toutes les périodes » reste un choix explicite du filtre Période
 * (code self::TOUTES), une ou plusieurs quinzaines cochées gardent leur sens.
 *
 * Quinzaine antérieure non soldée pour un livreur : il y a encore une commission « Créée » (à
 * valider), ou son solde n'est pas nul — commissions retenues − dépenses validées de la quinzaine
 * − déjà versé, mêmes montants que le résumé de l'écran. Ce solde est celui de la chaîne de fiches
 * de paie : un paiement de fiche est versé net des dépenses (FIFO sur les commissions,
 * CommissionEnveloppePartAllocationService), donc une quinzaine payée dont une commission reste
 * « Partielle » à hauteur des dépenses a un solde nul et sort de la vue. Un solde négatif (dépense
 * validée après le paiement, reportée sur la fiche suivante) reste dans la vue : sans lui, la
 * quinzaine qui porte le report afficherait ce report comme encore dû.
 *
 * Conséquence vérifiée par les tests : seules sortent de la vue des quinzaines de solde nul, donc
 * le reste à payer de chaque livreur est le même que sur « Toutes les périodes », sans dépense
 * déduite deux fois ni oubliée. Le brut, les dépenses et le versé affichés ne portent, eux, que
 * sur les quinzaines retenues.
 */
final class PerimetreParDefautLivreurs
{
    /** Code du filtre Période pour « Toutes les périodes ». */
    public const TOUTES = 'toutes';

    public const LIBELLE = 'Quinzaine en cours et quinzaines non soldées';

    /**
     * @param  array<string, list<string>>  $quinzainesAnterieures  codes non soldés par livreur
     */
    private function __construct(
        public readonly Carbon $debutCourant,
        public readonly array $quinzainesAnterieures,
    ) {}

    /**
     * Lit le filtre Période : « Toutes les périodes » explicite, quinzaines cochées, ou rien
     * (vue par défaut). « Toutes » l'emporte sur les quinzaines cochées avec lui.
     *
     * @return array{toutes: bool, codes: array<int, string>, par_defaut: bool}
     */
    public static function lireFiltre(array|string|null $input): array
    {
        $toutes = in_array(self::TOUTES, is_array($input) ? $input : [$input], true);
        $codes = $toutes ? [] : CommissionPeriodeFilter::normaliserCodes($input);

        return ['toutes' => $toutes, 'codes' => $codes, 'par_defaut' => ! $toutes && $codes === []];
    }

    public static function calculer(string $orgId): self
    {
        [$debut] = PeriodeComptableService::dateRangeForCode(PeriodeComptableService::periodeCouranteLivreur());
        $avant = $debut->toDateString();

        $mois = fn (string $col) => "SUBSTR({$col}, 1, 7)";
        $moitie = fn (string $col) => "CASE WHEN SUBSTR({$col}, 9, 2) <= '15' THEN 'P1' ELSE 'P2' END";

        // Par livreur et quinzaine antérieure : à payer − versé, et commissions encore « Créée ».
        $soldes = [];
        $commissions = CommissionEnveloppePart::query()
            ->join('commission_enveloppes as e', 'e.id', '=', 'commission_enveloppe_parts.enveloppe_id')
            ->where('e.organization_id', $orgId)
            ->where('e.earned_at', '<', $avant)
            ->where('commission_enveloppe_parts.beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            ->where('commission_enveloppe_parts.statut', '!=', StatutCommission::ANNULEE->value)
            ->toBase()
            ->selectRaw("commission_enveloppe_parts.beneficiaire_id as livreur, {$mois('e.earned_at')} as mois, {$moitie('e.earned_at')} as moitie")
            ->selectRaw('SUM(COALESCE(commission_enveloppe_parts.montant_actuel, commission_enveloppe_parts.montant_net)) - SUM(commission_enveloppe_parts.montant_verse) as solde')
            ->selectRaw('SUM(CASE WHEN commission_enveloppe_parts.statut = ? THEN 1 ELSE 0 END) as nb_creees', [StatutCommission::CREEE->value])
            ->groupByRaw("commission_enveloppe_parts.beneficiaire_id, {$mois('e.earned_at')}, {$moitie('e.earned_at')}")
            ->get();
        foreach ($commissions as $l) {
            $soldes[(string) $l->livreur][$l->mois.'-'.$l->moitie] = ['solde' => (float) $l->solde, 'creees' => (int) $l->nb_creees > 0];
        }

        $depenses = Depense::query()
            ->where('organization_id', $orgId)
            ->where('beneficiaire_type', 'livreur')
            ->where('statut', StatutDepense::VALIDE->value)
            ->where('date_depense', '<', $avant)
            ->toBase()
            ->selectRaw("beneficiaire_id as livreur, {$mois('date_depense')} as mois, {$moitie('date_depense')} as moitie, SUM(montant) as montant")
            ->groupByRaw("beneficiaire_id, {$mois('date_depense')}, {$moitie('date_depense')}")
            ->get();
        foreach ($depenses as $l) {
            $code = $l->mois.'-'.$l->moitie;
            $soldes[(string) $l->livreur][$code] ??= ['solde' => 0.0, 'creees' => false];
            $soldes[(string) $l->livreur][$code]['solde'] -= (float) $l->montant;
        }

        $nonSoldees = [];
        foreach ($soldes as $livreur => $quinzaines) {
            foreach ($quinzaines as $code => $q) {
                if ($q['creees'] || abs($q['solde']) > 0.009) {
                    $nonSoldees[$livreur][] = $code;
                }
            }
        }

        return new self($debut, $nonSoldees);
    }

    /**
     * Restreint les commissions (à appliquer dans le whereHas('enveloppe') de la requête des parts
     * de livreurs) à la quinzaine en cours et aux quinzaines antérieures non soldées de chaque
     * livreur. Une seule condition dans la même sous-requête que l'organisation et la période,
     * plutôt qu'une sous-requête par quinzaine : le livreur est lu sur la part
     * (commission_enveloppe_parts.beneficiaire_id).
     *
     * @param  Builder<CommissionEnveloppe>  $enveloppes
     */
    public function restreindreEnveloppes(Builder $enveloppes): void
    {
        $enveloppes->where(function (Builder $q) {
            $q->where('earned_at', '>=', $this->debutCourant->toDateString());
            foreach ($this->livreursParQuinzaine() as $code => $livreurIds) {
                $q->orWhere(fn (Builder $o) => $o
                    ->whereIn('commission_enveloppe_parts.beneficiaire_id', $livreurIds)
                    ->where(fn (Builder $d) => CommissionPeriodeFilter::appliquer($d, [$code], 'earned_at', colonneDate: true)));
            }
        });
    }

    /**
     * Dépenses déduites sans filtre Statut : celles de la quinzaine en cours et des quinzaines
     * antérieures non soldées du livreur — jamais celles d'une quinzaine sortie de la vue.
     *
     * @param  array<int, string>  $livreurIds
     * @param  array<int, string>|null  $siteIds  Filtre Agence optionnel — sans effet si vide.
     * @return array<string, float>
     */
    public function fraisDepenses(string $orgId, array $livreurIds, ?array $siteIds = null): array
    {
        if ($livreurIds === []) {
            return [];
        }

        $query = Depense::where('organization_id', $orgId)
            ->where('beneficiaire_type', 'livreur')
            ->whereIn('beneficiaire_id', $livreurIds)
            ->where('statut', StatutDepense::VALIDE->value)
            ->where(function ($q) {
                $q->where('date_depense', '>=', $this->debutCourant->toDateString());
                foreach (array_keys($this->livreursParQuinzaine()) as $code) {
                    $q->orWhere(fn ($o) => CommissionPeriodeFilter::appliquer($o, [$code], 'date_depense', colonneDate: true));
                }
            });
        if (! empty($siteIds)) {
            $query->whereIn('site_id', $siteIds);
        }

        $debut = $this->debutCourant->toDateString();
        $frais = [];
        foreach ($query->get(['beneficiaire_id', 'date_depense', 'montant']) as $d) {
            $livreur = (string) $d->beneficiaire_id;
            $date = Carbon::parse($d->date_depense);
            if ($date->toDateString() >= $debut
                || in_array(PeriodeComptableService::codeForLivreur($date), $this->quinzainesAnterieures[$livreur] ?? [], true)) {
                $frais[$livreur] = ($frais[$livreur] ?? 0.0) + (float) $d->montant;
            }
        }

        return $frais;
    }

    /** @return array<string, list<string>> livreurs concernés par chaque quinzaine antérieure */
    private function livreursParQuinzaine(): array
    {
        $parCode = [];
        foreach ($this->quinzainesAnterieures as $livreur => $codes) {
            foreach ($codes as $code) {
                $parCode[$code][] = (string) $livreur;
            }
        }
        ksort($parCode);

        return $parCode;
    }
}
