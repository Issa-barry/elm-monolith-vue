<?php

namespace App\Services\Rh;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Enums\TypeContrat;
use App\Models\Absence;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Site;
use App\Models\User;
use App\Services\SiteScopeService;
use App\Support\Periodes\Periode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Tableau de bord RH, Stat-RH, lot R1 (docs/tableau-de-bord-rh.md) : effectifs, absences et
 * alertes. Strictement consultatif : il lit les salariés, leurs contrats et leurs absences sans rien
 * modifier, et ne crée aucune règle RH.
 *
 * - **Périmètre** : celui des listes RH (agence actuelle du salarié, `employes.site_id`) :
 *   toutes les agences avec la vision de toutes les agences, sinon les agences de rattachement
 *   (SiteScopeService). Un salarié sans agence n'est compté qu'avec la vision de toutes les agences.
 *   Les dossiers supprimés ne sont jamais comptés.
 * - **Un bloc par liste nominative** (décision du 26/11/2026) : effectifs = `rh-employes.read`,
 *   contrats = `rh-contrats.read`, absences = `rh-absences.read`. Sans la permission, le bloc
 *   n'est ni calculé ni envoyé. Tous les agrégats sont affichés, sans masquage des petits effectifs.
 * - **Absences** : enregistrées seulement (annulées exclues), jours calendaires dates incluses
 *   (Absence::dureeEnJours), comptés sur les jours **écoulés** de la période (jusqu'à aujourd'hui)
 *   et dans la période d'emploi connue du salarié. Absence injustifiée = uniquement le motif de code
 *   `absence_injustifiee`.
 * - **Taux d'absentéisme** (décision du 26/11/2026) = jours d'absence / jours de présence attendus
 *   × 100, en jours calendaires, sur les seuls salariés dont la période d'emploi est connue : date
 *   d'embauche renseignée et, pour un salarié sorti, date de sortie renseignée. Les autres sont
 *   exclus du numérateur comme du dénominateur et comptés à part ; sans salarié retenu, pas de taux.
 * - **Agence** : toujours l'agence actuelle du salarié, y compris pour les absences passées
 *   (l'historique des affectations est daté à la saisie, sans date d'effet).
 */
class TableauDeBordRhService
{
    /** Périodes proposées : celles des autres tableaux de bord, dates libres comprises. */
    public const PERIODES = Periode::TABLEAU_DE_BORD;

    /** Code figé du motif « Absence injustifiée » (MotifsAbsenceParDefaut). */
    public const MOTIF_INJUSTIFIEE = 'absence_injustifiee';

    /** Horizon d'alerte des fins de CDD, en jours à partir d'aujourd'hui. */
    public const HORIZON_FIN_CDD = 30;

    /** Statuts d'un salarié encore présent dans l'effectif. */
    private const PRESENTS = [StatutEmploye::ACTIF, StatutEmploye::SUSPENDU];

    public function __construct(
        private readonly SiteScopeService $siteScope,
    ) {}

    /** Au moins une des trois listes RH est consultable : condition d'accès à la page. */
    public static function peutConsulter(User $user): bool
    {
        return $user->can('rh-employes.read') || $user->can('rh-absences.read') || $user->can('rh-contrats.read');
    }

    /**
     * Agences que l'utilisateur peut choisir : celles du filtre des listes RH.
     *
     * @return list<array{value: string, label: string}>
     */
    public function agencesProposees(User $user): array
    {
        $sites = $user->voitToutesLesAgences()
            ? Site::where('organization_id', $user->organization_id)->orderBy('nom')->get(['id', 'nom'])
            : $user->sites()->where('sites.organization_id', $user->organization_id)->orderBy('sites.nom')->get(['sites.id', 'sites.nom']);

        return $sites->map(fn (Site $s) => ['value' => (string) $s->id, 'label' => $s->nom])->values()->all();
    }

    /**
     * @param  list<array{value: string, label: string}>  $agences  agences proposées (agencesProposees())
     * @param  ?string  $agence  agence choisie, déjà vérifiée ; null = tout le périmètre
     * @return array<string, mixed>
     */
    public function calculer(User $user, Periode $periode, array $agences, ?string $agence, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();
        $aujourdhui = $maintenant->startOfDay();

        $voitEffectifs = $user->can('rh-employes.read');
        $voitContrats = $user->can('rh-contrats.read');
        $voitAbsences = $user->can('rh-absences.read');

        // Salariés du périmètre, toutes situations (sortis compris : entrées, sorties, absentéisme).
        $salaries = $this->salaries($user, $agence)->get(['id', 'personne_id', 'matricule', 'site_id', 'fonction_rh_id', 'service_rh_id', 'statut', 'date_embauche', 'date_sortie']);

        return [
            'periode' => [...$periode->pourFront(self::PERIODES), 'libelle' => $periode->libelle()],
            'agences' => $agences,
            'agence' => $agence,
            'perimetre_limite' => ! $user->voitToutesLesAgences(),
            'blocs' => ['effectifs' => $voitEffectifs, 'contrats' => $voitContrats, 'absences' => $voitAbsences],
            'effectifs' => $voitEffectifs ? $this->effectifs($salaries, $periode) : null,
            'effectifs_agences' => $voitEffectifs ? $this->effectifsParAgence($salaries, $periode, $agences, $agence) : null,
            'a_completer' => $voitEffectifs ? $this->aCompleter($salaries) : null,
            'contrats' => $voitContrats ? $this->contrats($user, $salaries) : null,
            'fins_cdd' => $voitContrats ? $this->finsCdd($user, $salaries, $aujourdhui, $voitEffectifs) : null,
            'absences' => $voitAbsences ? $this->absences($user, $salaries, $periode, $aujourdhui, $voitEffectifs) : null,
        ];
    }

    /** @return Builder<Employe> */
    private function salaries(User $user, ?string $agence): Builder
    {
        $query = Employe::query()->where('organization_id', $user->organization_id);
        $this->siteScope->applyToQuery($query, $user);

        if ($agence !== null) {
            $query->where('site_id', $agence);
        }

        return $query;
    }

    /** @param  Collection<int, Employe>  $salaries */
    private static function presents(Collection $salaries): Collection
    {
        return $salaries->filter(fn (Employe $e) => in_array($e->statut, self::PRESENTS, true));
    }

    /**
     * Effectif à ce jour, entrées et sorties de la période.
     *
     * @param  Collection<int, Employe>  $salaries
     * @return array{actifs: int, suspendus: int, entrees: int, sorties: int}
     */
    private function effectifs(Collection $salaries, Periode $periode): array
    {
        return [
            'actifs' => $salaries->where('statut', StatutEmploye::ACTIF)->count(),
            'suspendus' => $salaries->where('statut', StatutEmploye::SUSPENDU)->count(),
            'entrees' => $salaries->filter(fn (Employe $e) => self::entre($e, $periode))->count(),
            'sorties' => $salaries->filter(fn (Employe $e) => self::sorti($e, $periode))->count(),
        ];
    }

    private static function entre(Employe $e, Periode $periode): bool
    {
        return $e->date_embauche !== null && $periode->contient($e->date_embauche);
    }

    private static function sorti(Employe $e, Periode $periode): bool
    {
        return $e->statut === StatutEmploye::SORTI && $e->date_sortie !== null && $periode->contient($e->date_sortie);
    }

    /**
     * Effectif par agence (agence actuelle du salarié), du plus gros au plus petit effectif. Les
     * salariés sans agence forment une ligne à part.
     *
     * @param  Collection<int, Employe>  $salaries
     * @param  list<array{value: string, label: string}>  $agences
     * @return list<array{site_id: ?string, site: string, actifs: int, suspendus: int, entrees: int, sorties: int}>
     */
    private function effectifsParAgence(Collection $salaries, Periode $periode, array $agences, ?string $agence): array
    {
        $libelles = array_column($agences, 'label', 'value');

        return $salaries
            ->groupBy(fn (Employe $e) => (string) ($e->site_id ?? ''))
            ->map(fn (Collection $groupe, string $siteId) => [
                'site_id' => $siteId === '' ? null : $siteId,
                'site' => $siteId === '' ? 'Sans agence' : ($libelles[$siteId] ?? '—'),
                'actifs' => $groupe->where('statut', StatutEmploye::ACTIF)->count(),
                'suspendus' => $groupe->where('statut', StatutEmploye::SUSPENDU)->count(),
                'entrees' => $groupe->filter(fn (Employe $e) => self::entre($e, $periode))->count(),
                'sorties' => $groupe->filter(fn (Employe $e) => self::sorti($e, $periode))->count(),
            ])
            // Une agence sans présent, sans entrée ni sortie sur la période n'apporte rien.
            ->filter(fn (array $l) => $l['actifs'] + $l['suspendus'] + $l['entrees'] + $l['sorties'] > 0)
            ->sortBy([['actifs', 'desc'], ['suspendus', 'desc'], ['site', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Dossiers des salariés présents à compléter (données manquantes à la saisie RH).
     * Les salariés sortis sans date de sortie (anciennes fiches, avant l'action « Enregistrer le
     * départ ») sont signalés à part : ils sont exclus du taux d'absentéisme.
     *
     * @param  Collection<int, Employe>  $salaries
     * @return array{presents: int, sans_date_embauche: int, sans_fonction: int, sans_service: int, sans_agence: int, sortis_sans_date: int}
     */
    private function aCompleter(Collection $salaries): array
    {
        $presents = self::presents($salaries);

        return [
            'presents' => $presents->count(),
            'sans_date_embauche' => $presents->whereNull('date_embauche')->count(),
            'sans_fonction' => $presents->whereNull('fonction_rh_id')->count(),
            'sans_service' => $presents->whereNull('service_rh_id')->count(),
            'sans_agence' => $presents->whereNull('site_id')->count(),
            'sortis_sans_date' => $salaries->filter(fn (Employe $e) => self::sortiSansDate($e))->count(),
        ];
    }

    private static function sortiSansDate(Employe $e): bool
    {
        return $e->statut === StatutEmploye::SORTI && $e->date_sortie === null;
    }

    /**
     * Salarié retenu pour le taux d'absentéisme (décision du 26/11/2026) : période d'emploi connue,
     * soit une date d'embauche, et une date de sortie s'il est sorti. Un salarié suspendu reste
     * retenu (aucune date de suspension n'existe).
     */
    private static function eligibleAuTaux(Employe $e): bool
    {
        return $e->date_embauche !== null && ! self::sortiSansDate($e);
    }

    /** Fin de la période d'emploi : la date de sortie d'un salarié sorti, sinon ouverte. */
    private static function finEmploi(Employe $e): ?\DateTimeInterface
    {
        return $e->statut === StatutEmploye::SORTI ? $e->date_sortie : null;
    }

    /**
     * Contrats en cours des salariés présents (un seul contrat actif à la fois) et salariés actifs
     * sans contrat actif.
     *
     * @param  Collection<int, Employe>  $salaries
     * @return array{cdi: int, cdd: int, sans_contrat: int}
     */
    private function contrats(User $user, Collection $salaries): array
    {
        $presents = self::presents($salaries);
        $actifs = $this->contratsActifs($user, $presents->pluck('id')->all());
        $avecContrat = $actifs->pluck('employe_id')->unique()->flip();

        return [
            'cdi' => $actifs->where('type_contrat', TypeContrat::CDI)->unique('employe_id')->count(),
            'cdd' => $actifs->where('type_contrat', TypeContrat::CDD)->unique('employe_id')->count(),
            'sans_contrat' => $presents
                ->where('statut', StatutEmploye::ACTIF)
                ->reject(fn (Employe $e) => $avecContrat->has($e->id))
                ->count(),
        ];
    }

    /**
     * CDD actifs dont la fin prévue est dépassée, ou atteinte dans HORIZON_FIN_CDD jours : les plus
     * urgents d'abord. La fin prévue ne fait jamais sortir le salarié (docs/rh-depart-salarie.md) :
     * c'est un rappel à traiter (renouvellement, nouveau contrat ou départ), pas une erreur.
     *
     * @param  Collection<int, Employe>  $salaries
     * @return list<array<string, mixed>>
     */
    private function finsCdd(User $user, Collection $salaries, CarbonImmutable $aujourdhui, bool $lienFiche): array
    {
        $presents = self::presents($salaries)->keyBy('id');
        $limite = $aujourdhui->addDays(self::HORIZON_FIN_CDD);

        return $this->contratsActifs($user, $presents->keys()->all())
            ->filter(fn (Contrat $c) => $c->type_contrat === TypeContrat::CDD && $c->date_fin !== null && $c->date_fin->lte($limite))
            ->sortBy(fn (Contrat $c) => $c->date_fin->toDateString())
            ->map(function (Contrat $c) use ($presents, $aujourdhui, $lienFiche) {
                /** @var Employe $e */
                $e = $presents->get($c->employe_id);
                $employe = $c->employe;
                $jours = (int) $aujourdhui->diffInDays($c->date_fin->copy()->startOfDay(), false);
                $depasse = $jours < 0;

                return [
                    'contrat_id' => $c->id,
                    'employe_id' => $lienFiche ? $e->id : null,
                    'salarie' => $employe?->nom_complet ?? '—',
                    'matricule' => $e->matricule,
                    'site' => $employe?->site?->nom ?? 'Sans agence',
                    'date_fin' => $c->date_fin->toDateString(),
                    'jours' => $jours,
                    'statut' => $depasse ? 'echeance_depassee' : 'expire_bientot',
                    'statut_label' => match (true) {
                        $depasse => 'Fin dépassée',
                        $jours === 0 => 'Se termine aujourd’hui',
                        default => 'Se termine dans '.$jours.' jour'.($jours > 1 ? 's' : ''),
                    },
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $employeIds
     * @return Collection<int, Contrat>
     */
    private function contratsActifs(User $user, array $employeIds): Collection
    {
        if ($employeIds === []) {
            return collect();
        }

        return Contrat::query()
            ->where('organization_id', $user->organization_id)
            ->where('statut_contrat', StatutContrat::ACTIF->value)
            ->whereIn('employe_id', $employeIds)
            ->with(['employe' => fn ($q) => $q->select(['id', 'personne_id', 'site_id'])->with(['personne', 'site:id,nom'])])
            ->get(['id', 'employe_id', 'type_contrat', 'date_debut', 'date_fin', 'statut_contrat']);
    }

    /**
     * Absences enregistrées des salariés du périmètre sur les jours écoulés de la période, limitées
     * à la période d'emploi connue de chaque salarié. Le taux ne retient que les salariés éligibles
     * (eligibleAuTaux) ; le dénominateur et le nombre de salariés retenus ou exclus révèlent
     * l'effectif : ils ne sont envoyés qu'avec `rh-employes.read`.
     *
     * @param  Collection<int, Employe>  $salaries
     * @return array<string, mixed>
     */
    private function absences(User $user, Collection $salaries, Periode $periode, CarbonImmutable $aujourdhui, bool $voitEffectifs): array
    {
        $ids = $salaries->pluck('id')->all();
        $debut = $periode->debut?->startOfDay();
        $finPeriode = $periode->fin?->startOfDay();
        // Jours écoulés seulement : une absence prévue plus tard ne compte pas encore.
        $fin = $finPeriode === null || $finPeriode->gt($aujourdhui) ? $aujourdhui : $finPeriode;
        $commencee = $debut === null || $debut->lte($fin);

        $absences = $ids === [] || ! $commencee
            ? collect()
            : Absence::query()
                ->where('organization_id', $user->organization_id)
                ->enregistrees()
                ->whereIn('employe_id', $ids)
                ->where('date_debut', '<', $fin->addDay()->toDateString())
                ->when($debut !== null, fn ($q) => $q->where('date_fin', '>=', $debut->toDateString()))
                ->with('motif:id,code,libelle,ordre')
                ->get(['id', 'employe_id', 'motif_absence_id', 'date_debut', 'date_fin']);

        $parId = $salaries->keyBy('id');
        // Jours d'une absence : jours écoulés de la période ∩ période d'emploi connue du salarié.
        $jours = function (Absence $a) use ($parId, $debut, $fin): int {
            [$debutEmploi, $finEmploi] = self::bornesEmploi($parId->get($a->employe_id), $debut, $fin);

            return self::joursDans($a->date_debut, $a->date_fin, $debutEmploi, $finEmploi);
        };
        $injustifiees = $absences->filter(fn (Absence $a) => $a->motif?->code === self::MOTIF_INJUSTIFIEE);
        $totalJours = (int) $absences->sum($jours);
        $taux = $debut === null || ! $commencee ? null : $this->taux($salaries, $absences, $jours, $debut, $fin);

        $parMotif = $absences
            ->groupBy('motif_absence_id')
            ->map(fn (Collection $groupe) => [
                'motif_id' => (string) $groupe->first()->motif_absence_id,
                'libelle' => $groupe->first()->motif?->libelle ?? '—',
                'injustifiee' => $groupe->first()->motif?->code === self::MOTIF_INJUSTIFIEE,
                'absences' => $groupe->count(),
                'jours' => (int) $groupe->sum($jours),
            ])
            ->sortBy([['jours', 'desc'], ['libelle', 'asc']])
            ->values()
            ->all();

        $demain = $aujourdhui->addDay()->toDateString();
        $enCours = $ids === [] ? 0 : Absence::query()
            ->where('organization_id', $user->organization_id)
            ->enregistrees()
            ->whereIn('employe_id', $ids)
            ->where('date_debut', '<', $demain)
            ->where('date_fin', '>=', $aujourdhui->toDateString())
            ->distinct()
            ->count('employe_id');
        $aVenir = $ids === [] || ($finPeriode !== null && $finPeriode->lte($aujourdhui)) ? 0 : Absence::query()
            ->where('organization_id', $user->organization_id)
            ->enregistrees()
            ->whereIn('employe_id', $ids)
            ->where('date_debut', '>=', $demain)
            ->when($finPeriode !== null, fn ($q) => $q->where('date_debut', '<', $finPeriode->addDay()->toDateString()))
            ->count();

        return [
            'jours' => $totalJours,
            'absences' => $absences->count(),
            'salaries' => $absences->pluck('employe_id')->unique()->count(),
            'taux' => $taux['taux'] ?? null,
            // null : « Toutes les périodes » ou période pas encore commencée.
            'taux_motif' => $taux === null ? 'periode' : ($taux['taux'] === null ? 'aucun_salarie' : null),
            'jours_attendus' => $voitEffectifs ? ($taux['jours_attendus'] ?? null) : null,
            'salaries_taux' => $voitEffectifs ? ($taux['retenus'] ?? null) : null,
            'salaries_exclus' => $voitEffectifs ? ($taux['exclus'] ?? null) : null,
            'injustifiees_jours' => (int) $injustifiees->sum($jours),
            'injustifiees_absences' => $injustifiees->count(),
            'absents_aujourdhui' => $enCours,
            'a_venir' => $aVenir,
            'jusqu_au' => $commencee ? $fin->toDateString() : null,
            'par_motif' => $parMotif,
        ];
    }

    /**
     * Taux d'absentéisme (décision du 26/11/2026) sur les seuls salariés éligibles : jours
     * d'absence ÷ jours de présence attendus × 100, les deux limités à la période d'emploi.
     * `taux` null s'il n'y a aucun jour attendu (aucun salarié éligible en poste sur la période) :
     * « — » plutôt que « 0 % ».
     *
     * - retenus : salariés éligibles en poste au moins un jour de la période ;
     * - exclus : salariés non éligibles (sans date d'embauche, ou sortis sans date de sortie), sauf
     *   ceux dont la sortie connue précède la période.
     *
     * @param  Collection<int, Employe>  $salaries
     * @param  Collection<int, Absence>  $absences
     * @param  \Closure(Absence): int  $jours
     * @return array{taux: ?float, jours_attendus: int, retenus: int, exclus: int}
     */
    private function taux(Collection $salaries, Collection $absences, \Closure $jours, CarbonImmutable $debut, CarbonImmutable $fin): array
    {
        $attendusParSalarie = $salaries
            ->filter(fn (Employe $e) => self::eligibleAuTaux($e))
            ->mapWithKeys(fn (Employe $e) => [$e->id => self::joursDans($e->date_embauche, self::finEmploi($e), $debut, $fin)])
            ->filter(fn (int $n) => $n > 0);
        $attendus = (int) $attendusParSalarie->sum();
        $joursRetenus = (int) $absences
            ->filter(fn (Absence $a) => $attendusParSalarie->has($a->employe_id))
            ->sum($jours);
        $exclus = $salaries
            ->reject(fn (Employe $e) => self::eligibleAuTaux($e))
            ->reject(fn (Employe $e) => $e->statut === StatutEmploye::SORTI && $e->date_sortie !== null && $e->date_sortie->lt($debut))
            ->count();

        return [
            'taux' => $attendus === 0 ? null : round($joursRetenus / $attendus * 100, 4),
            'jours_attendus' => $attendus,
            'retenus' => $attendusParSalarie->count(),
            'exclus' => $exclus,
        ];
    }

    /**
     * Intersection de [$debut, $fin] avec la période d'emploi connue du salarié (embauche, sortie) ;
     * une borne inconnue n'est pas appliquée.
     *
     * @return array{0: ?CarbonImmutable, 1: CarbonImmutable}
     */
    private static function bornesEmploi(?Employe $e, ?CarbonImmutable $debut, CarbonImmutable $fin): array
    {
        $embauche = $e?->date_embauche === null ? null : CarbonImmutable::instance($e->date_embauche)->startOfDay();
        $sortie = $e === null || self::finEmploi($e) === null ? null : CarbonImmutable::instance(self::finEmploi($e))->startOfDay();

        $borneDebut = match (true) {
            $embauche === null => $debut,
            $debut === null => $embauche,
            default => $embauche->max($debut),
        };

        return [$borneDebut, $sortie === null ? $fin : $sortie->min($fin)];
    }

    /**
     * Nombre de jours calendaires (bornes incluses) communs à [$de, $a] et à [$debut, $fin] ; une
     * borne nulle est ouverte.
     */
    private static function joursDans(?\DateTimeInterface $de, ?\DateTimeInterface $a, ?CarbonImmutable $debut, CarbonImmutable $fin): int
    {
        $de = $de === null ? null : CarbonImmutable::instance($de)->startOfDay();
        $a = $a === null ? $fin : CarbonImmutable::instance($a)->startOfDay();
        $borneDebut = match (true) {
            $de === null => $debut,
            $debut === null => $de,
            default => $de->max($debut),
        };
        $borneFin = $a->min($fin);

        if ($borneDebut === null || $borneDebut->gt($borneFin)) {
            return 0;
        }

        return Absence::dureeEnJours($borneDebut, $borneFin);
    }
}
