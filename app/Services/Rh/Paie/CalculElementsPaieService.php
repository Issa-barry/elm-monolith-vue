<?php

namespace App\Services\Rh\Paie;

use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\PeriodiciteRemuneration;
use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutContrat;
use App\Enums\StatutSaisieRemuneration;
use App\Enums\StatutTypeRemuneration;
use App\Models\AffectationRemuneration;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\SaisieRemuneration;
use App\Models\TransmissionPaieLigne;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcule les éléments de rémunération d'un mois à partir des données RH vivantes
 * (docs/transmission-paie.md, ADR 0040). Lecture seule : rien n'est écrit. Sert à préparer un
 * brouillon de transmission, à vérifier au moment de la validation que rien n'a changé, et à
 * signaler les changements intervenus depuis une version figée.
 *
 * Règles de calcul (jours calendaires, comme PaieCalculService) :
 * - salaire de base : porté par le contrat, au prorata des jours du contrat dans le mois et dans la
 *   période d'emploi (date d'embauche, date de sortie) ;
 * - montant fixe proratisable : montant × jours de droit / jours du mois ; non proratisable :
 *   montant entier dès un jour de droit (un changement de montant en cours de mois s'applique alors
 *   au mois entier) ;
 * - pourcentage du salaire de base : taux × salaire de base de la même partie du mois (proratisable),
 *   ou du salaire mensuel du contrat en vigueur au dernier jour de droit (non proratisable) ;
 * - saisi chaque mois : le montant (ou le taux) validé du mois, sans prorata ;
 * - ponctuel : le montant entier, une seule fois, dans son mois ;
 * - avantage en nature : calculé de la même façon, mais « valorisé » : hors net à payer.
 * Un jour de droit est un jour où l'affectation, la période d'emploi et la période d'application du
 * type se recouvrent, avant la date de désactivation du type.
 */
class CalculElementsPaieService
{
    private Carbon $debutMois;

    private Carbon $finMois;

    private int $joursMois;

    /** @var list<array<string, mixed>> */
    private array $anomalies = [];

    /**
     * @return array{lignes: list<array<string, mixed>>, anomalies: list<array<string, mixed>>, nb_salaries: int, total_verse: float, total_valorise: float, empreinte: string}
     */
    public function calculer(string $organizationId, int $annee, int $mois): array
    {
        $this->debutMois = Carbon::create($annee, $mois, 1)->startOfDay();
        $this->finMois = $this->debutMois->copy()->endOfMonth()->startOfDay();
        $this->joursMois = $this->debutMois->daysInMonth;
        $this->anomalies = [];

        $contrats = $this->contrats($organizationId)->groupBy('employe_id');
        $affectations = $this->affectations($organizationId);
        $parEmploye = $affectations->groupBy('employe_id');

        $employeIds = $contrats->keys()->merge($parEmploye->keys())->unique()->values();
        $employes = Employe::whereIn('id', $employeIds)->where('organization_id', $organizationId)
            ->with(['personne', 'site:id,nom', 'fonction:id,libelle'])
            ->get()
            ->sortBy('nom_complet');

        $lignes = [];
        foreach ($employes as $employe) {
            array_push($lignes, ...$this->lignesEmploye(
                $employe,
                $contrats->get($employe->id, collect()),
                $parEmploye->get($employe->id, collect()),
            ));
        }

        $verse = 0.0;
        $valorise = 0.0;
        foreach ($lignes as $ligne) {
            if ($ligne['versement'] === TransmissionPaieLigne::VERSEMENT_VALORISE) {
                $valorise += $ligne['montant'];
            } else {
                $verse += $ligne['montant'];
            }
        }

        return [
            'lignes' => $lignes,
            'anomalies' => $this->anomalies,
            'nb_salaries' => count(array_unique(array_column($lignes, 'employe_id'))),
            'total_verse' => round($verse, 2),
            'total_valorise' => round($valorise, 2),
            'empreinte' => self::empreinte($lignes),
        ];
    }

    /**
     * Empreinte des lignes (SHA-256) : identique pour les mêmes montants, calculés ou relus d'une
     * version figée. Toute modification d'une ligne la change.
     *
     * @param  iterable<array<string, mixed>|TransmissionPaieLigne>  $lignes
     */
    public static function empreinte(iterable $lignes): string
    {
        $normalisees = [];
        foreach ($lignes as $ligne) {
            $l = $ligne instanceof TransmissionPaieLigne ? $ligne->getAttributes() : $ligne;
            $normalisees[] = implode('|', [
                $l['employe_id'], $l['origine'], $l['contrat_id'] ?? '', $l['affectation_id'] ?? '', $l['saisie_id'] ?? '',
                $l['type_remuneration_id'] ?? '', $l['code'] ?? '', $l['versement'],
                self::jour($l['du']), self::jour($l['au']), (int) $l['jours'],
                self::nombre($l['montant_reference'] ?? null), self::nombre($l['base_calcul'] ?? null),
                self::nombre($l['taux'] ?? null), self::nombre($l['montant']),
            ]);
        }
        sort($normalisees);

        return hash('sha256', implode("\n", $normalisees));
    }

    /** @return Collection<int, Contrat> */
    private function contrats(string $organizationId): Collection
    {
        // Contrat actif sur le mois, ou terminé / rompu en cours de mois (sa date de fin le borne).
        return Contrat::where('organization_id', $organizationId)
            ->whereDate('date_debut', '<=', $this->finMois->toDateString())
            ->where(fn ($q) => $q->where('statut_contrat', StatutContrat::ACTIF->value)
                ->orWhere(fn ($q2) => $q2->whereIn('statut_contrat', [StatutContrat::TERMINE->value, StatutContrat::ROMPU->value])
                    ->whereNotNull('date_fin')))
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $this->debutMois->toDateString()))
            ->orderBy('date_debut')
            ->get();
    }

    /** @return Collection<int, AffectationRemuneration> */
    private function affectations(string $organizationId): Collection
    {
        return AffectationRemuneration::where('organization_id', $organizationId)
            ->whereIn('statut', StatutAffectationRemuneration::occupantLaPeriode())
            ->enVigueurEntre($this->debutMois, $this->finMois)
            ->with(['type', 'saisies' => fn ($q) => $q->where('annee', $this->debutMois->year)->where('mois', $this->debutMois->month)])
            ->get()
            ->sortBy(fn (AffectationRemuneration $a) => $a->type->libelle.'|'.$a->date_debut->toDateString());
    }

    /**
     * @param  Collection<int, Contrat>  $contrats
     * @param  Collection<int, AffectationRemuneration>  $affectations
     * @return list<array<string, mixed>>
     */
    private function lignesEmploye(Employe $employe, Collection $contrats, Collection $affectations): array
    {
        $emploiDu = $this->max($this->debutMois, $employe->date_embauche);
        $emploiAu = $this->min($this->finMois, $employe->date_sortie);
        if ($emploiDu->gt($emploiAu)) {
            $this->anomalie($employe, 'hors_emploi', 'avertissement',
                'Salarié hors de sa période d\'emploi sur tout le mois (embauche ou sortie) : rien n\'est transmis.');

            return [];
        }

        if ($employe->site_id === null) {
            $this->anomalie($employe, 'sans_agence', 'avertissement', 'Salarié sans agence : la charge ne pourra être imputée à aucune agence.');
        }

        $segments = $this->segmentsContrat($employe, $contrats, $emploiDu, $emploiAu);
        $lignes = [];
        foreach ($segments as $segment) {
            $lignes[] = $this->ligneSalaireBase($employe, $segment);
        }

        foreach ($affectations as $affectation) {
            if ($affectation->statut === StatutAffectationRemuneration::A_VALIDER) {
                $this->anomalie($employe, 'affectation_a_valider', 'bloquante',
                    "« {$affectation->type->libelle} » : proposition à valider qui touche le mois.");

                continue;
            }
            if ($affectation->date_fin_demandee !== null && $affectation->date_fin_demandee->lt($this->finMois)) {
                $this->anomalie($employe, 'fin_a_valider', 'bloquante',
                    "« {$affectation->type->libelle} » : fin d'effet au {$affectation->date_fin_demandee->format('d/m/Y')} à valider.");

                continue;
            }

            array_push($lignes, ...$this->lignesAffectation($employe, $affectation, $affectations, $segments, $emploiDu, $emploiAu));
        }

        if ($segments === [] && count($lignes) > 0) {
            $this->anomalie($employe, 'contrat_absent', 'bloquante', 'Primes ou avantages sur le mois, mais aucun contrat en cours.');
        }

        foreach (array_keys($lignes) as $i) {
            $lignes[$i]['ordre'] = $i;
        }

        return $lignes;
    }

    /**
     * Parties du mois couvertes par un contrat (dans la période d'emploi).
     *
     * @param  Collection<int, Contrat>  $contrats
     * @return list<array{contrat: Contrat, du: Carbon, au: Carbon}>
     */
    private function segmentsContrat(Employe $employe, Collection $contrats, Carbon $emploiDu, Carbon $emploiAu): array
    {
        $segments = [];
        foreach ($contrats as $contrat) {
            $du = $this->max($emploiDu, $contrat->date_debut);
            $au = $this->min($emploiAu, $contrat->date_fin);
            if ($du->gt($au)) {
                continue;
            }
            foreach ($segments as $autre) {
                if ($du->lte($autre['au']) && $au->gte($autre['du'])) {
                    $this->anomalie($employe, 'contrats_chevauchants', 'bloquante', 'Deux contrats se chevauchent sur le mois.');
                }
            }
            if ($contrat->salaire_base === null || (float) $contrat->salaire_base <= 0) {
                $this->anomalie($employe, 'salaire_absent', 'bloquante', 'Contrat sans salaire de base.');
            }
            $segments[] = ['contrat' => $contrat, 'du' => $du, 'au' => $au];
        }

        return $segments;
    }

    /** @param  array{contrat: Contrat, du: Carbon, au: Carbon}  $segment */
    private function ligneSalaireBase(Employe $employe, array $segment): array
    {
        $contrat = $segment['contrat'];
        $salaire = (float) $contrat->salaire_base;
        $jours = $this->jours($segment['du'], $segment['au']);
        $montant = $this->prorata($salaire, $jours);

        return $this->ligne($employe, [
            'contrat_id' => $contrat->id,
            'origine' => TransmissionPaieLigne::ORIGINE_CONTRAT,
            'libelle' => 'Salaire de base (contrat '.$contrat->type_contrat->label().')',
            'versement' => TransmissionPaieLigne::VERSEMENT_VERSE,
            'proratisable' => true,
            'du' => $segment['du'], 'au' => $segment['au'], 'jours' => $jours,
            'montant_reference' => $salaire,
            'montant' => $montant,
            'detail_calcul' => $jours < $this->joursMois
                ? $this->gnf($salaire)." × {$jours}/{$this->joursMois} jours"
                : 'Salaire mensuel du contrat',
        ]);
    }

    /**
     * @param  Collection<int, AffectationRemuneration>  $toutes
     * @param  list<array{contrat: Contrat, du: Carbon, au: Carbon}>  $segments
     * @return list<array<string, mixed>>
     */
    private function lignesAffectation(Employe $employe, AffectationRemuneration $affectation, Collection $toutes, array $segments, Carbon $emploiDu, Carbon $emploiAu): array
    {
        $type = $affectation->type;
        $du = $this->max($this->max($emploiDu, $affectation->date_debut), $type->date_debut_application);
        $au = $this->min($this->min($emploiAu, $affectation->date_fin), $type->date_fin_application);
        // Double garantie : un type désactivé ne produit plus rien à partir de sa date d'effet.
        if ($type->statut === StatutTypeRemuneration::INACTIF) {
            $au = $type->desactive_au !== null ? $this->min($au, $type->desactive_au->copy()->subDay()) : $du->copy()->subDay();
        }
        if ($du->gt($au)) {
            return [];
        }

        $commun = [
            'contrat_id' => null,
            'origine' => TransmissionPaieLigne::ORIGINE_AFFECTATION,
            'affectation_id' => $affectation->id,
            'type_remuneration_id' => $type->id,
            'code' => $type->code,
            'libelle' => $type->libelle,
            'categorie' => $type->categorie->value,
            'versement' => $type->categorie->estValorise() ? TransmissionPaieLigne::VERSEMENT_VALORISE : TransmissionPaieLigne::VERSEMENT_VERSE,
            'mode_calcul' => $type->mode_calcul->value,
            'periodicite' => $type->periodicite->value,
            'nature_montant' => $type->nature_montant->value,
            'proratisable' => $type->proratisable,
        ];
        $pourcentage = $type->mode_calcul === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE;

        if ($type->nature_montant === NatureMontantRemuneration::VARIABLE) {
            return $this->ligneSaisie($employe, $affectation, $commun, $segments, $du, $au, $pourcentage);
        }

        $proratise = $type->proratisable && $type->periodicite !== PeriodiciteRemuneration::PONCTUELLE;
        // Non proratisable : le montant qui succède en cours de mois vaut pour le mois entier.
        if (! $proratise && $this->remplaceeDansLeMois($affectation, $toutes)) {
            return [];
        }

        if ($pourcentage) {
            return $this->lignesPourcentage($employe, $commun, $segments, $du, $au, (float) $affectation->taux, $proratise);
        }

        $montantAttribue = (float) $affectation->montant;
        $jours = $this->jours($du, $au);
        $montant = $proratise ? $this->prorata($montantAttribue, $jours) : $montantAttribue;

        return [$this->ligne($employe, [
            ...$commun,
            'du' => $du, 'au' => $au, 'jours' => $jours,
            'montant_reference' => $montantAttribue,
            'montant' => $montant,
            'detail_calcul' => $proratise && $jours < $this->joursMois
                ? $this->gnf($montantAttribue)." × {$jours}/{$this->joursMois} jours"
                : ($type->periodicite === PeriodiciteRemuneration::PONCTUELLE ? 'Montant attribué (ponctuel)' : 'Montant attribué'),
        ])];
    }

    /**
     * @param  array<string, mixed>  $commun
     * @param  list<array{contrat: Contrat, du: Carbon, au: Carbon}>  $segments
     * @return list<array<string, mixed>>
     */
    private function lignesPourcentage(Employe $employe, array $commun, array $segments, Carbon $du, Carbon $au, float $taux, bool $proratise): array
    {
        $lignes = [];

        if ($proratise) {
            foreach ($segments as $segment) {
                $sDu = $this->max($du, $segment['du']);
                $sAu = $this->min($au, $segment['au']);
                if ($sDu->gt($sAu)) {
                    continue;
                }
                $jours = $this->jours($sDu, $sAu);
                $base = $this->prorata((float) $segment['contrat']->salaire_base, $jours);
                $lignes[] = $this->ligne($employe, [
                    ...$commun,
                    'contrat_id' => $segment['contrat']->id,
                    'du' => $sDu, 'au' => $sAu, 'jours' => $jours,
                    'base_calcul' => $base, 'taux' => $taux,
                    'montant' => round($base * $taux / 100, 2),
                    'detail_calcul' => $this->taux($taux).' × '.$this->gnf($base).($jours < $this->joursMois ? " (salaire de base sur {$jours}/{$this->joursMois} jours)" : ' (salaire de base)'),
                ]);
            }
        } else {
            $segment = $this->segmentAu($segments, $au);
            if ($segment !== null) {
                $base = (float) $segment['contrat']->salaire_base;
                $lignes[] = $this->ligne($employe, [
                    ...$commun,
                    'contrat_id' => $segment['contrat']->id,
                    'du' => $du, 'au' => $au, 'jours' => $this->jours($du, $au),
                    'base_calcul' => $base, 'taux' => $taux,
                    'montant' => round($base * $taux / 100, 2),
                    'detail_calcul' => $this->taux($taux).' × '.$this->gnf($base).' (salaire mensuel du contrat)',
                ]);
            }
        }

        if ($lignes === []) {
            $this->anomalie($employe, 'base_absente', 'bloquante', "« {$commun['libelle']} » : pourcentage du salaire de base, mais aucun contrat sur la période.");
        }

        return $lignes;
    }

    /**
     * @param  array<string, mixed>  $commun
     * @param  list<array{contrat: Contrat, du: Carbon, au: Carbon}>  $segments
     * @return list<array<string, mixed>>
     */
    private function ligneSaisie(Employe $employe, AffectationRemuneration $affectation, array $commun, array $segments, Carbon $du, Carbon $au, bool $pourcentage): array
    {
        /** @var SaisieRemuneration|null $saisie */
        $saisie = $affectation->saisies->first();
        $mois = sprintf('%02d/%d', $this->debutMois->month, $this->debutMois->year);

        if ($saisie === null) {
            $this->anomalie($employe, 'montant_mois_absent', 'avertissement',
                "« {$commun['libelle']} » : aucun montant saisi pour {$mois}, rien n'est transmis pour cet élément.");

            return [];
        }
        if ($saisie->statut === StatutSaisieRemuneration::A_VALIDER) {
            $this->anomalie($employe, 'montant_mois_a_valider', 'bloquante', "« {$commun['libelle']} » : montant de {$mois} à valider.");

            return [];
        }

        $ligne = [...$commun, 'saisie_id' => $saisie->id, 'du' => $du, 'au' => $au, 'jours' => $this->jours($du, $au)];

        if ($pourcentage) {
            $segment = $this->segmentAu($segments, $au);
            if ($segment === null) {
                $this->anomalie($employe, 'base_absente', 'bloquante', "« {$commun['libelle']} » : pourcentage du salaire de base, mais aucun contrat sur la période.");

                return [];
            }
            $base = (float) $segment['contrat']->salaire_base;
            $taux = (float) $saisie->taux;

            return [$this->ligne($employe, [
                ...$ligne,
                'contrat_id' => $segment['contrat']->id,
                'base_calcul' => $base, 'taux' => $taux,
                'montant' => round($base * $taux / 100, 2),
                'detail_calcul' => $this->taux($taux)." saisi pour {$mois} × ".$this->gnf($base),
            ])];
        }

        if ((float) $saisie->montant <= 0) {
            return [];
        }

        return [$this->ligne($employe, [
            ...$ligne,
            'montant_reference' => (float) $saisie->montant,
            'montant' => (float) $saisie->montant,
            'detail_calcul' => "Montant saisi pour {$mois}",
        ])];
    }

    /** @param  Collection<int, AffectationRemuneration>  $toutes */
    private function remplaceeDansLeMois(AffectationRemuneration $affectation, Collection $toutes): bool
    {
        return $toutes->contains(fn (AffectationRemuneration $autre) => $autre->remplace_affectation_id === $affectation->id
            && $autre->statut === StatutAffectationRemuneration::VALIDEE
            && $autre->date_debut->lte($this->finMois));
    }

    /**
     * @param  list<array{contrat: Contrat, du: Carbon, au: Carbon}>  $segments
     * @return array{contrat: Contrat, du: Carbon, au: Carbon}|null
     */
    private function segmentAu(array $segments, Carbon $jour): ?array
    {
        $retenu = null;
        foreach ($segments as $segment) {
            if ($segment['du']->lte($jour)) {
                $retenu = $segment;
            }
        }

        return $retenu;
    }

    /** @param  array<string, mixed>  $valeurs */
    private function ligne(Employe $employe, array $valeurs): array
    {
        return [
            'employe_id' => $employe->id,
            'matricule' => $employe->matricule,
            'nom_salarie' => $employe->nom_complet,
            'site_id' => $employe->site_id,
            'site_nom' => $employe->site?->nom,
            'fonction_libelle' => $employe->fonction?->libelle,
            'contrat_id' => null,
            'affectation_id' => null,
            'saisie_id' => null,
            'type_remuneration_id' => null,
            'code' => null,
            'categorie' => null,
            'mode_calcul' => null,
            'periodicite' => null,
            'nature_montant' => null,
            'montant_reference' => null,
            'base_calcul' => null,
            'taux' => null,
            ...$valeurs,
            'du' => $valeurs['du']->toDateString(),
            'au' => $valeurs['au']->toDateString(),
            'jours_mois' => $this->joursMois,
            'montant' => round((float) $valeurs['montant'], 2),
        ];
    }

    private function anomalie(Employe $employe, string $code, string $niveau, string $message): void
    {
        foreach ($this->anomalies as $existante) {
            if ($existante['employe_id'] === $employe->id && $existante['code'] === $code && $existante['message'] === $message) {
                return;
            }
        }

        $this->anomalies[] = [
            'code' => $code,
            'niveau' => $niveau,
            'employe_id' => $employe->id,
            'salarie' => $employe->nom_complet,
            'site_id' => $employe->site_id,
            'message' => $message,
        ];
    }

    private function prorata(float $montant, int $jours): float
    {
        return $jours < $this->joursMois ? round($montant * $jours / $this->joursMois, 2) : $montant;
    }

    private function jours(Carbon $du, Carbon $au): int
    {
        return (int) $du->diffInDays($au) + 1;
    }

    private function max(Carbon $a, ?Carbon $b): Carbon
    {
        return $b !== null && $b->copy()->startOfDay()->gt($a) ? $b->copy()->startOfDay() : $a->copy();
    }

    private function min(Carbon $a, ?Carbon $b): Carbon
    {
        return $b !== null && $b->copy()->startOfDay()->lt($a) ? $b->copy()->startOfDay() : $a->copy();
    }

    private function gnf(float $montant): string
    {
        return number_format($montant, 0, ',', ' ').' GNF';
    }

    private function taux(float $taux): string
    {
        return rtrim(rtrim(number_format($taux, 2, ',', ' '), '0'), ',').' %';
    }

    private static function jour(mixed $valeur): string
    {
        return $valeur instanceof \DateTimeInterface ? $valeur->format('Y-m-d') : substr((string) $valeur, 0, 10);
    }

    private static function nombre(mixed $valeur): string
    {
        return $valeur === null || $valeur === '' ? '' : number_format((float) $valeur, 2, '.', '');
    }
}
