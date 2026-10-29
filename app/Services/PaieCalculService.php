<?php

namespace App\Services;

use App\Enums\StatutDepense;
use App\Enums\StatutLignePaie;
use App\Enums\StatutPeriodePaie;
use App\Enums\TypeVariablePaie;
use App\Models\Depense;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\PaieVariable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calcul d'une ligne de paie de la Comptabilité (docs/paie.md, ADR 0041).
 *
 * Les gains (salaire de base, primes, indemnités, allocations) et les avantages en nature viennent
 * uniquement de la transmission RH reprise (App\Services\Comptabilite\ReprisePaieService) : ils sont
 * copiés sur la ligne et jamais recalculés ici. Ce service n'ajoute que les retenues propres à la
 * Comptabilité (dépenses validées : avances, retenues…), puis calcule le net et le reste à payer.
 */
class PaieCalculService
{
    /**
     * Période de paie du mois, créée vide (brouillon) si elle n'existe pas encore. Ses lignes
     * n'arrivent que par la reprise de la transmission RH : aucune ligne n'est déduite des contrats.
     */
    public function getOrCreerPeriode(string $orgId, int $mois, int $annee): PaiePeriode
    {
        return PaiePeriode::firstOrCreate(
            ['organization_id' => $orgId, 'mois' => $mois, 'annee' => $annee],
            ['statut' => StatutPeriodePaie::BROUILLON],
        );
    }

    /**
     * Net d'une ligne : gains transmis par RH (salaire de base, primes) moins les retenues. Les
     * avantages en nature restent hors du brut et du net. Une variable de gain saisie à la main n'est
     * plus reprise : les gains viennent de la transmission RH.
     */
    public function calculerLigne(PaieLigne $ligne): void
    {
        $ligne->loadMissing('variables');

        $retenues = [
            TypeVariablePaie::AVANCE->value => 0,
            TypeVariablePaie::RETENUE->value => 0,
            TypeVariablePaie::ABSENCE->value => 0,
            TypeVariablePaie::AUTRE_DEDUCTION->value => 0,
        ];

        foreach ($ligne->variables as $variable) {
            if ($variable->type->estDeduction()) {
                $retenues[$variable->type->value] += (float) $variable->montant;
            }
        }

        $brut = round((float) $ligne->salaire_base + (float) $ligne->total_primes + (float) $ligne->total_autres_gains, 2);
        $deductions = round(array_sum($retenues), 2);

        $net = max(0, $brut - $deductions);
        $dejaPaye = (float) $ligne->deja_paye;
        $resteAPayer = max(0, $net - $dejaPaye);

        $statut = match (true) {
            $dejaPaye >= $net && $net > 0 => StatutLignePaie::PAYE,
            $dejaPaye > 0 => StatutLignePaie::PARTIELLEMENT_PAYE,
            default => StatutLignePaie::CALCULE,
        };

        $ligne->update([
            'total_avances' => $retenues[TypeVariablePaie::AVANCE->value],
            'total_retenues' => $retenues[TypeVariablePaie::RETENUE->value],
            'total_absences' => $retenues[TypeVariablePaie::ABSENCE->value],
            'total_autres_deductions' => $retenues[TypeVariablePaie::AUTRE_DEDUCTION->value],
            'brut' => $brut,
            'deductions' => $deductions,
            'net' => $net,
            'reste_a_payer' => $resteAPayer,
            'statut' => $statut,
        ]);
    }

    public function calculerPeriode(PaiePeriode $periode): void
    {
        $periode->loadMissing('lignes');

        DB::transaction(function () use ($periode) {
            foreach ($periode->lignes as $ligne) {
                $this->importerDepenses($ligne, $periode);
                $ligne->load('variables');
                $this->calculerLigne($ligne);
            }
        });
    }

    /**
     * Reprend en paie les dépenses validées du salarié, si leur type porte une retenue (avance,
     * retenue, absence, autre déduction). Un type sans effet sur la paie (remboursement de frais…) ou
     * un ancien type « prime » / « autre gain » n'est jamais repris : les primes viennent du
     * référentiel RH (docs/paie.md).
     *
     * Mois de rattachement : celui de la dépense. Si la paie de ce mois est déjà validée et que la
     * dépense n'y figure pas (validée après coup), elle est reprise en régularisation dans le premier
     * mois suivant encore ouvert. Une période validée n'est jamais modifiée.
     */
    public function importerDepenses(PaieLigne $ligne, PaiePeriode $periode): void
    {
        if ($periode->statut instanceof StatutPeriodePaie && $periode->statut->estVerrouille()) {
            return;
        }

        $debutMois = Carbon::create($periode->annee, $periode->mois, 1)->startOfMonth();
        $fin = $debutMois->copy()->endOfMonth();
        $debut = $this->debutDesRegularisations($periode, $debutMois);

        $ligne->variables()->whereNotNull('depense_id')->delete();

        $dejaRetenues = PaieVariable::whereNotNull('depense_id')
            ->whereHas('ligne', fn ($l) => $l->where('employe_id', $ligne->employe_id)
                ->where('paie_periode_id', '!=', $periode->id)
                ->whereHas('periode', fn ($p) => $p->where('organization_id', $periode->organization_id)
                    ->whereIn('statut', self::statutsVerrouilles())))
            ->pluck('depense_id')
            ->flip();

        $depenses = Depense::where('organization_id', $periode->organization_id)
            ->where('beneficiaire_type', 'employe')
            ->where('beneficiaire_id', $ligne->employe_id)
            ->where('statut', StatutDepense::VALIDE->value)
            ->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59'])
            ->with('depenseType')
            ->get();

        foreach ($depenses as $depense) {
            if ($dejaRetenues->has($depense->id)) {
                continue;
            }

            $typePaie = TypeVariablePaie::tryFrom($depense->depenseType?->type_paie ?? '');
            if ($typePaie === null || ! $typePaie->estDeduction()) {
                continue;
            }

            $libelle = $depense->depenseType->libelle ?? 'Dépense';
            if ($depense->commentaire) {
                $libelle .= ' — '.$depense->commentaire;
            }
            if ($depense->date_depense->lt($debutMois)) {
                $libelle = mb_substr($libelle, 0, 125).' (régularisation '.$depense->date_depense->format('m/Y').')';
            }

            $ligne->variables()->create([
                'depense_id' => $depense->id,
                'type' => $typePaie,
                'libelle' => mb_substr($libelle, 0, 150),
                'montant' => (float) $depense->montant,
                'note' => null,
            ]);
        }
    }

    public function recalculerApresPaiement(PaieLigne $ligne): void
    {
        $dejaPaye = (float) $ligne->paiements()->sum('montant');
        $net = (float) $ligne->net;
        $resteAPayer = max(0, $net - $dejaPaye);

        $statut = match (true) {
            $dejaPaye >= $net && $net > 0 => StatutLignePaie::PAYE,
            $dejaPaye > 0 => StatutLignePaie::PARTIELLEMENT_PAYE,
            default => StatutLignePaie::CALCULE,
        };

        $ligne->update([
            'deja_paye' => $dejaPaye,
            'reste_a_payer' => $resteAPayer,
            'statut' => $statut,
        ]);
    }

    /** @return list<string> */
    public static function statutsVerrouilles(): array
    {
        return array_values(array_map(
            fn (StatutPeriodePaie $s) => $s->value,
            array_filter(StatutPeriodePaie::cases(), fn (StatutPeriodePaie $s) => $s->estVerrouille()),
        ));
    }

    /**
     * Premier jour dont une dépense peut encore être reprise dans cette période : le mois lui-même,
     * plus les mois précédents consécutifs déjà validés (leurs dépenses tardives y sont régularisées).
     */
    private function debutDesRegularisations(PaiePeriode $periode, Carbon $debutMois): Carbon
    {
        $verrouilles = PaiePeriode::where('organization_id', $periode->organization_id)
            ->whereIn('statut', self::statutsVerrouilles())
            ->get(['annee', 'mois'])
            ->mapWithKeys(fn (PaiePeriode $p) => [sprintf('%04d-%02d', $p->annee, $p->mois) => true]);

        $debut = $debutMois->copy();
        $precedent = $debutMois->copy()->subMonthNoOverflow();
        while ($verrouilles->has($precedent->format('Y-m'))) {
            $debut = $precedent->copy();
            $precedent->subMonthNoOverflow();
        }

        return $debut;
    }
}
