<?php

namespace App\Services\Rh;

use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\PeriodiciteRemuneration;
use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutContrat;
use App\Enums\StatutTypeRemuneration;
use App\Models\AffectationRemuneration;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\TypeRemuneration;
use Carbon\CarbonInterface;

/**
 * Rémunération mensuelle totale d'un salarié à une date (docs/primes-avantages.md, AFF-016) : le
 * salaire de base du contrat en cours + les primes récurrentes versées en argent, en vigueur ce
 * jour-là. Indicateur de la fiche salarié, en lecture seule : ce n'est PAS la paie du mois (aucun
 * prorata, aucun montant mensuel saisi) — la paie reste calculée par CalculElementsPaieService.
 *
 * Mêmes règles d'éligibilité que la paie : affectation validée, type actif dans sa période
 * d'application, salarié dans sa période d'emploi. Ne sont jamais additionnés au total : les
 * primes ponctuelles, les montants saisis chaque mois, les propositions à valider et les avantages
 * en nature (valorisés, hors net à payer — totalisés à part).
 */
class RemunerationMensuelleService
{
    /**
     * @return array{
     *     date: string,
     *     hors_emploi: string|null,
     *     contrat: array{id: string, type_contrat_label: string, date_debut: string|null, date_fin: string|null}|null,
     *     salaire_base: float|null,
     *     primes: list<array{id: string, libelle: string, categorie_label: string, montant: float, detail: string}>,
     *     total_primes: float,
     *     total: float|null,
     *     avantages_nature: list<array{id: string, libelle: string, categorie_label: string, montant: float, detail: string}>,
     *     total_avantages_nature: float,
     *     non_comptees: list<array{id: string, libelle: string, raison: string}>
     * }
     */
    public function calculer(Employe $employe, ?CarbonInterface $jour = null): array
    {
        $jour = ($jour ?? now())->copy()->startOfDay();

        $resultat = [
            'date' => $jour->toDateString(),
            'hors_emploi' => $this->horsEmploi($employe, $jour),
            'contrat' => null,
            'salaire_base' => null,
            'primes' => [],
            'total_primes' => 0.0,
            'total' => null,
            'avantages_nature' => [],
            'total_avantages_nature' => 0.0,
            'non_comptees' => [],
        ];

        if ($resultat['hors_emploi'] !== null) {
            return $resultat;
        }

        $contrat = $this->contratEnCours($employe, $jour);
        $salaire = $contrat !== null && (float) $contrat->salaire_base > 0 ? (float) $contrat->salaire_base : null;
        if ($contrat !== null) {
            $resultat['contrat'] = [
                'id' => $contrat->id,
                'type_contrat_label' => $contrat->type_contrat->label(),
                'date_debut' => $contrat->date_debut?->toDateString(),
                'date_fin' => $contrat->date_fin?->toDateString(),
            ];
        }
        $resultat['salaire_base'] = $salaire;

        $affectations = AffectationRemuneration::where('organization_id', $employe->organization_id)
            ->where('employe_id', $employe->id)
            ->whereIn('statut', StatutAffectationRemuneration::occupantLaPeriode())
            ->enVigueurEntre($jour, $jour)
            ->with('type')
            ->get()
            ->sortBy(fn (AffectationRemuneration $a) => $a->type->libelle.'|'.$a->date_debut->toDateString());

        foreach ($affectations as $affectation) {
            $type = $affectation->type;
            $raison = $this->raisonNonComptee($affectation, $type, $jour, $salaire);
            if ($raison !== null) {
                $resultat['non_comptees'][] = ['id' => $affectation->id, 'libelle' => $type->libelle, 'raison' => $raison];

                continue;
            }

            $ligne = [
                'id' => $affectation->id,
                'libelle' => $type->libelle,
                'categorie_label' => $type->categorie->label(),
                ...$this->montant($affectation, $type, $salaire),
            ];

            if ($type->categorie->estValorise()) {
                $resultat['avantages_nature'][] = $ligne;
                $resultat['total_avantages_nature'] += $ligne['montant'];
            } else {
                $resultat['primes'][] = $ligne;
                $resultat['total_primes'] += $ligne['montant'];
            }
        }

        $resultat['total_primes'] = round($resultat['total_primes'], 2);
        $resultat['total_avantages_nature'] = round($resultat['total_avantages_nature'], 2);
        // Sans salaire de base, aucun total n'est inventé : la fiche affiche l'absence de contrat.
        $resultat['total'] = $salaire !== null ? round($salaire + $resultat['total_primes'], 2) : null;

        return $resultat;
    }

    private function horsEmploi(Employe $employe, CarbonInterface $jour): ?string
    {
        if ($employe->date_sortie !== null && $employe->date_sortie->lt($jour)) {
            return 'Salarié sorti le '.$employe->date_sortie->format('d/m/Y').' : plus aucune rémunération en cours.';
        }
        if ($employe->date_embauche !== null && $employe->date_embauche->gt($jour)) {
            return 'Embauche prévue le '.$employe->date_embauche->format('d/m/Y').' : aucune rémunération en cours.';
        }

        return null;
    }

    private function contratEnCours(Employe $employe, CarbonInterface $jour): ?Contrat
    {
        return Contrat::where('organization_id', $employe->organization_id)
            ->where('employe_id', $employe->id)
            ->where('statut_contrat', StatutContrat::ACTIF->value)
            ->whereDate('date_debut', '<=', $jour->toDateString())
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $jour->toDateString()))
            ->orderByDesc('date_debut')
            ->first();
    }

    private function raisonNonComptee(AffectationRemuneration $affectation, TypeRemuneration $type, CarbonInterface $jour, ?float $salaire): ?string
    {
        if ($affectation->statut === StatutAffectationRemuneration::A_VALIDER) {
            return 'À valider : comptée après sa validation.';
        }
        if (! $this->typeApplicable($type, $jour)) {
            return 'Type désactivé ou hors de sa période d’application : plus versée.';
        }
        if ($type->periodicite === PeriodiciteRemuneration::PONCTUELLE) {
            return 'Ponctuelle : versée une seule fois, en '.$affectation->date_debut->format('m/Y').'.';
        }
        if ($type->nature_montant === NatureMontantRemuneration::VARIABLE) {
            return 'Montant saisi chaque mois : pas de montant récurrent.';
        }
        if ($type->mode_calcul === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE && $salaire === null) {
            return 'Pourcentage du salaire de base : aucun contrat en cours.';
        }

        return null;
    }

    /** Même règle que la paie : période d'application du type, et rien à partir de sa désactivation. */
    private function typeApplicable(TypeRemuneration $type, CarbonInterface $jour): bool
    {
        if ($type->date_debut_application !== null && $type->date_debut_application->gt($jour)) {
            return false;
        }
        if ($type->date_fin_application !== null && $type->date_fin_application->lt($jour)) {
            return false;
        }
        if ($type->statut === StatutTypeRemuneration::INACTIF) {
            return $type->desactive_au !== null && $type->desactive_au->gt($jour);
        }

        return true;
    }

    /** @return array{montant: float, detail: string} */
    private function montant(AffectationRemuneration $affectation, TypeRemuneration $type, ?float $salaire): array
    {
        if ($type->mode_calcul === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE) {
            $taux = (float) $affectation->taux;

            return [
                'montant' => round((float) $salaire * $taux / 100, 2),
                'detail' => rtrim(rtrim(number_format($taux, 2, ',', ' '), '0'), ',').' % du salaire de base',
            ];
        }

        return ['montant' => round((float) $affectation->montant, 2), 'detail' => 'Montant mensuel'];
    }
}
