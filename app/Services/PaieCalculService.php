<?php

namespace App\Services;

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
        $dejaPayé = (float) $ligne->deja_paye;
        $resteAPayer = max(0, $net - $dejaPayé);

        $statut = match (true) {
            $dejaPayé >= $net && $net > 0 => StatutLignePaie::PAYE,
            $dejaPayé > 0 => StatutLignePaie::PARTIELLEMENT_PAYE,
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

    private function importerDepenses(PaieLigne $ligne, PaiePeriode $periode): void
    {
        $debut = Carbon::create($periode->annee, $periode->mois, 1)->startOfMonth();
        $fin   = $debut->copy()->endOfMonth();

        // Supprimer les variables auto-importées précédemment
        $ligne->variables()->whereNotNull('depense_id')->delete();

        // Récupérer les dépenses approuvées pour cet employé dans la période
        $depenses = Depense::where('employe_id', $ligne->employe_id)
            ->where('statut', 'approuve')
            ->whereBetween('date_depense', [$debut->toDateString(), $fin->toDateString()])
            ->whereHas('depenseType', fn ($q) => $q->whereNotNull('type_paie'))
            ->with('depenseType')
            ->get();

        foreach ($depenses as $depense) {
            $libelle = $depense->depenseType->libelle;
            if ($depense->commentaire) {
                $libelle .= ' — ' . $depense->commentaire;
            }

            $ligne->variables()->create([
                'depense_id' => $depense->id,
                'type'       => TypeVariablePaie::from($depense->depenseType->type_paie),
                'libelle'    => $libelle,
                'montant'    => (float) $depense->montant,
                'note'       => null,
            ]);
        }
    }

    public function recalculerApresPaiement(PaieLigne $ligne): void
    {
        $dejaPayé = (float) $ligne->paiements()->sum('montant');
        $net = (float) $ligne->net;
        $resteAPayer = max(0, $net - $dejaPayé);

        $statut = match (true) {
            $dejaPayé >= $net && $net > 0 => StatutLignePaie::PAYE,
            $dejaPayé > 0 => StatutLignePaie::PARTIELLEMENT_PAYE,
            default => StatutLignePaie::CALCULE,
        };

        $ligne->update([
            'deja_paye' => $dejaPayé,
            'reste_a_payer' => $resteAPayer,
            'statut' => $statut,
        ]);
    }

    private function joursActifs(Contrat $contrat, Carbon $debut, Carbon $fin, int $joursMois): float
    {
        $dateDebut = Carbon::parse($contrat->date_debut)->startOfDay();
        $dateFin   = $contrat->date_fin ? Carbon::parse($contrat->date_fin)->startOfDay() : null;

        $effectifDebut = $dateDebut->gt($debut) ? $dateDebut : $debut->copy()->startOfDay();
        $effectifFin   = ($dateFin && $dateFin->lt($fin)) ? $dateFin : $fin->copy()->startOfDay();

        if ($effectifDebut->gt($effectifFin)) {
            return 0;
        }

        return (float) ($effectifDebut->diffInDays($effectifFin) + 1);
    }
}
