<?php

namespace App\Services;

use App\Enums\StatutDepense;
use App\Enums\StatutLignePaie;
use App\Enums\TypeVariablePaie;
use App\Models\Contrat;
use App\Models\Depense;
use App\Models\Employe;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaieCalculService
{
    public function genererLignes(PaiePeriode $periode): void
    {
        $orgId = $periode->organization_id;
        $debut = Carbon::create($periode->annee, $periode->mois, 1)->startOfMonth();
        $fin = $debut->copy()->endOfMonth();
        $joursMois = $debut->daysInMonth;

        $employes = Employe::where('organization_id', $orgId)
            ->whereHas('contrats', fn ($q) => $q->where('statut_contrat', 'actif')
                ->where('date_debut', '<=', $fin)
                ->where(fn ($q2) => $q2->whereNull('date_fin')->orWhere('date_fin', '>=', $debut))
            )
            ->with(['contrats' => fn ($q) => $q->where('statut_contrat', 'actif')
                ->where('date_debut', '<=', $fin)
                ->where(fn ($q2) => $q2->whereNull('date_fin')->orWhere('date_fin', '>=', $debut))
                ->orderByDesc('date_debut')
                ->limit(1),
            ])
            ->get();

        DB::transaction(function () use ($employes, $periode, $debut, $fin, $joursMois) {
            foreach ($employes as $employe) {
                $contrat = $employe->contrats->first();
                if (! $contrat) {
                    continue;
                }

                if (PaieLigne::where('paie_periode_id', $periode->id)
                    ->where('employe_id', $employe->id)
                    ->exists()
                ) {
                    continue;
                }

                $joursActifs = $this->joursActifs($contrat, $debut, $fin, $joursMois);
                $salaireBase = (float) $contrat->salaire_base;
                $prorata = $joursActifs < $joursMois
                    ? round($salaireBase * $joursActifs / $joursMois, 2)
                    : $salaireBase;

                PaieLigne::create([
                    'paie_periode_id' => $periode->id,
                    'employe_id' => $employe->id,
                    'contrat_id' => $contrat->id,
                    'salaire_base' => $prorata,
                    'jours_travailles' => $joursActifs,
                    'jours_periode' => $joursMois,
                    'total_primes' => 0,
                    'total_autres_gains' => 0,
                    'total_avances' => 0,
                    'total_retenues' => 0,
                    'total_absences' => 0,
                    'total_autres_deductions' => 0,
                    'brut' => $prorata,
                    'deductions' => 0,
                    'net' => $prorata,
                    'deja_paye' => 0,
                    'reste_a_payer' => $prorata,
                    'statut' => StatutLignePaie::EN_ATTENTE,
                ]);
            }
        });
    }

    public function calculerLigne(PaieLigne $ligne): void
    {
        $ligne->loadMissing('variables');

        $totaux = [
            TypeVariablePaie::PRIME->value => 0,
            TypeVariablePaie::AUTRE_GAIN->value => 0,
            TypeVariablePaie::AVANCE->value => 0,
            TypeVariablePaie::RETENUE->value => 0,
            TypeVariablePaie::ABSENCE->value => 0,
            TypeVariablePaie::AUTRE_DEDUCTION->value => 0,
        ];

        foreach ($ligne->variables as $variable) {
            $totaux[$variable->type->value] += (float) $variable->montant;
        }

        $brut = (float) $ligne->salaire_base
            + $totaux[TypeVariablePaie::PRIME->value]
            + $totaux[TypeVariablePaie::AUTRE_GAIN->value];

        $deductions = $totaux[TypeVariablePaie::AVANCE->value]
            + $totaux[TypeVariablePaie::RETENUE->value]
            + $totaux[TypeVariablePaie::ABSENCE->value]
            + $totaux[TypeVariablePaie::AUTRE_DEDUCTION->value];

        $net = max(0, $brut - $deductions);
        $dejaPaye = (float) $ligne->deja_paye;
        $resteAPayer = max(0, $net - $dejaPaye);

        $statut = match (true) {
            $dejaPaye >= $net && $net > 0 => StatutLignePaie::PAYE,
            $dejaPaye > 0 => StatutLignePaie::PARTIELLEMENT_PAYE,
            default => StatutLignePaie::CALCULE,
        };

        $ligne->update([
            'total_primes' => $totaux[TypeVariablePaie::PRIME->value],
            'total_autres_gains' => $totaux[TypeVariablePaie::AUTRE_GAIN->value],
            'total_avances' => $totaux[TypeVariablePaie::AVANCE->value],
            'total_retenues' => $totaux[TypeVariablePaie::RETENUE->value],
            'total_absences' => $totaux[TypeVariablePaie::ABSENCE->value],
            'total_autres_deductions' => $totaux[TypeVariablePaie::AUTRE_DEDUCTION->value],
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

    public function importerDepenses(PaieLigne $ligne, PaiePeriode $periode): void
    {
        $debut = Carbon::create($periode->annee, $periode->mois, 1)->startOfMonth();
        $fin = $debut->copy()->endOfMonth();

        $ligne->variables()->whereNotNull('depense_id')->delete();

        $depenses = Depense::where('beneficiaire_type', 'employe')
            ->where('beneficiaire_id', $ligne->employe_id)
            ->where('statut', StatutDepense::VALIDE->value)
            ->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString().' 23:59:59'])
            ->with('depenseType')
            ->get();

        foreach ($depenses as $depense) {
            $libelle = $depense->depenseType->libelle ?? 'Dépense';
            if ($depense->commentaire) {
                $libelle .= ' — '.$depense->commentaire;
            }

            $typePaie = TypeVariablePaie::tryFrom($depense->depenseType->type_paie ?? '')
                ?? TypeVariablePaie::AUTRE_DEDUCTION;

            $ligne->variables()->create([
                'depense_id' => $depense->id,
                'type' => $typePaie,
                'libelle' => $libelle,
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

    /**
     * Paiement global d'un salarié : répartit un montant en FIFO (période la
     * plus ancienne d'abord) sur ses lignes de paie impayées, sur toutes les
     * périodes — l'utilisateur paie un solde total, pas une période précise.
     */
    public function payerEmploye(string $employeId, string $orgId, float $montant, string $modePaiement, string $paidAt, ?string $note = null): void
    {
        $lignes = PaieLigne::with('periode')
            ->where('employe_id', $employeId)
            ->whereHas('periode', fn ($q) => $q->where('organization_id', $orgId))
            ->where('reste_a_payer', '>', 0)
            ->get()
            ->sortBy(fn (PaieLigne $l) => sprintf('%04d-%02d', $l->periode->annee, $l->periode->mois));

        $totalDisponible = (float) $lignes->sum('reste_a_payer');
        if ($montant > $totalDisponible + 0.01) {
            throw new \InvalidArgumentException("Le montant dépasse le reste à payer ({$totalDisponible} GNF).");
        }

        $touched = PeriodePayabilityChecker::touchedUntilAmount($lignes, $montant, fn ($l) => (float) $l->reste_a_payer);
        PeriodePayabilityChecker::assertLignesPayables($touched);

        $restant = $montant;
        foreach ($lignes as $ligne) {
            if ($restant <= 0.01) {
                break;
            }

            $alloue = min($restant, (float) $ligne->reste_a_payer);
            $ligne->paiements()->create([
                'montant' => $alloue,
                'date_paiement' => $paidAt,
                'mode_paiement' => $modePaiement,
                'note' => $note,
            ]);
            $this->recalculerApresPaiement($ligne);
            $restant -= $alloue;
        }
    }

    private function joursActifs(Contrat $contrat, Carbon $debut, Carbon $fin, int $joursMois): float
    {
        $dateDebut = Carbon::parse($contrat->date_debut)->startOfDay();
        $dateFin = $contrat->date_fin ? Carbon::parse($contrat->date_fin)->startOfDay() : null;

        $effectifDebut = $dateDebut->gt($debut) ? $dateDebut : $debut->copy()->startOfDay();
        $effectifFin = ($dateFin && $dateFin->lt($fin)) ? $dateFin : $fin->copy()->startOfDay();

        if ($effectifDebut->gt($effectifFin)) {
            return 0;
        }

        return (float) ($effectifDebut->diffInDays($effectifFin) + 1);
    }
}
