<?php

namespace App\Services\Comptabilite;

use App\Contracts\Rh\SourceDonneesPaie;
use App\Enums\AuditEvent;
use App\Enums\StatutLignePaie;
use App\Enums\StatutPeriodePaie;
use App\Enums\StatutTransmissionPaie;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Models\PaiePeriode;
use App\Models\PaieVariable;
use App\Models\TransmissionPaie;
use App\Models\TransmissionPaieLigne;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PaieCalculService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reprise de la transmission RH dans la paie de la Comptabilité (ADR 0041, docs/paie.md) : seul point
 * d'entrée des lignes d'une période de paie.
 *
 * - Source : la version transmise en vigueur du mois, lue par SourceDonneesPaie (contenu figé et
 *   vérifié par son empreinte). Rien n'est recalculé : les montants du mois sont copiés tels quels,
 *   par salarié (salaire de base, primes et indemnités versées, avantages en nature à part).
 * - Une version n'est reprise qu'une fois (prendreEnCharge, mise à jour conditionnelle) et
 *   n'alimente qu'une période (index unique). Une version plus récente transmise avant la validation
 *   de la paie remplace les lignes ; après validation, plus aucune reprise.
 * - Transaction unique, verrou sur l'organisation (le même que la transmission RH, qui ne peut donc
 *   pas transmettre pendant une reprise ou une validation de la paie) puis sur la période : en cas
 *   d'erreur, ni ligne, ni prise en charge, ni journal.
 *
 * Erreurs métier : InvalidArgumentException. Droits : AuthorizationException (403).
 */
class ReprisePaieService
{
    /** Préparer la paie d'un mois = modifier la période (PaiePolicy::update). */
    public const PERMISSION = 'rh-paie.update';

    public function __construct(
        private readonly SourceDonneesPaie $source,
        private readonly PaieCalculService $calcul,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Où en est la reprise du mois, pour l'écran Paiement salaire.
     *
     * @return array{courante: ?array<string, mixed>, reprise: ?array<string, mixed>, a_jour: bool, a_reprendre: bool}
     */
    public function etat(PaiePeriode $periode): array
    {
        $courante = $this->source->transmissionCourante($periode->organization_id, $periode->annee, $periode->mois);
        $reprise = $periode->transmission_paie_id !== null ? TransmissionPaie::find($periode->transmission_paie_id) : null;
        $verrouillee = $periode->statut instanceof StatutPeriodePaie && $periode->statut->estVerrouille();

        $brouillon = $verrouillee ? null : $this->source->brouillonEnCours($periode->organization_id, $periode->annee, $periode->mois);
        $aJour = $courante !== null && $reprise?->id === $courante->id;

        return [
            'courante' => $courante !== null ? $this->resume($courante) + [
                'transmise_le' => $courante->transmise_le?->format('d/m/Y à H:i'),
            ] : null,
            'reprise' => $reprise !== null ? $this->resume($reprise) + [
                'reprise_le' => $periode->reprise_le?->format('d/m/Y à H:i'),
                'reprise_par' => $periode->repreneur?->name,
            ] : null,
            // Brouillon RH, lecture seule et provisoire : jamais repris ni payé (ADR 0047).
            'brouillon' => $brouillon,
            'a_jour' => $aJour,
            'a_reprendre' => ! $verrouillee && $courante !== null && $reprise?->id !== $courante->id,
            'etape' => $this->etape($periode, $verrouillee, $courante, $aJour, $brouillon),
        ];
    }

    /**
     * Où en est la paie du mois, vue de la Comptabilité (ADR 0047) : code (couleur, StatusDot) et
     * libellé. Les étapes comptables priment sur l'état RH.
     *
     * @param  array<string, mixed>|null  $brouillon
     * @return array{code: string, label: string}
     */
    private function etape(PaiePeriode $periode, bool $verrouillee, ?TransmissionPaie $courante, bool $aJour, ?array $brouillon): array
    {
        if ($verrouillee) {
            return match ($periode->statut) {
                StatutPeriodePaie::PAYE => ['code' => 'paye', 'label' => 'Payée'],
                StatutPeriodePaie::CLOTURE => ['code' => 'cloture', 'label' => 'Clôturée'],
                default => ['code' => 'valide_rh', 'label' => 'Validée par la Comptabilité'],
            };
        }
        if ($courante !== null) {
            return $aJour
                ? ['code' => 'paie_reprise', 'label' => 'Reprise par la Comptabilité, à valider']
                : ['code' => 'paie_validee_rh', 'label' => 'Validée et figée par les RH'];
        }
        if ($brouillon !== null) {
            if (! $brouillon['a_jour']) {
                return ['code' => 'paie_a_recalculer', 'label' => 'Brouillon RH à recalculer'];
            }

            return $brouillon['nb_anomalies_bloquantes'] > 0
                ? ['code' => 'paie_brouillon', 'label' => 'Brouillon provisoire, anomalies à corriger côté RH']
                : ['code' => 'paie_prete_a_valider', 'label' => 'Prête à valider côté RH'];
        }

        return ['code' => 'paie_aucune', 'label' => 'Aucune paie préparée par les RH'];
    }

    /** Droits seuls (organisation, permission, toutes les agences), sans l'état de la période. */
    public function peutReprendre(PaiePeriode $periode, User $acteur): bool
    {
        return $acteur->organization_id === $periode->organization_id
            && $acteur->can(self::PERMISSION)
            && $acteur->agitSurToutesLesAgences();
    }

    /**
     * @param  string  $transmissionId  version affichée à l'écran : si une autre version a été
     *                                  transmise entre-temps, la reprise est refusée.
     */
    public function reprendre(PaiePeriode $periode, string $transmissionId, User $acteur): PaiePeriode
    {
        $this->exigerDroits($periode, $acteur);

        return DB::transaction(function () use ($periode, $transmissionId, $acteur) {
            Organization::whereKey($periode->organization_id)->lockForUpdate()->firstOrFail();
            $periode = PaiePeriode::whereKey($periode->getKey())->lockForUpdate()->firstOrFail();

            if ($periode->statut->estVerrouille()) {
                throw new InvalidArgumentException("La paie de {$periode->labelPeriode()} est déjà validée : aucune transmission ne peut plus y être reprise.");
            }

            $transmission = $this->versionAReprendre($periode, $transmissionId);

            $lignesPeriode = PaieLigne::where('paie_periode_id', $periode->id);
            if (PaiePaiement::whereIn('paie_ligne_id', (clone $lignesPeriode)->select('id'))->exists()) {
                throw new InvalidArgumentException('Des paiements sont déjà enregistrés sur cette période : la transmission ne peut pas être reprise.');
            }

            try {
                $elements = $this->source->lignes($transmission);
            } catch (ValidationException $e) {
                throw new InvalidArgumentException(collect($e->errors())->flatten()->first());
            }

            $precedente = $periode->transmission_paie_id !== null ? TransmissionPaie::find($periode->transmission_paie_id) : null;

            PaieVariable::whereIn('paie_ligne_id', (clone $lignesPeriode)->select('id'))->delete();
            (clone $lignesPeriode)->delete();

            $this->creerLignes($periode, $elements);
            $totaux = $this->verifierConcordance($periode, $transmission);

            try {
                $this->source->prendreEnCharge($transmission, $acteur, 'paie_periode:'.$periode->id);
            } catch (ValidationException $e) {
                throw new InvalidArgumentException(collect($e->errors())->flatten()->first());
            }

            $periode->update([
                'transmission_paie_id' => $transmission->id,
                'reprise_par' => $acteur->id,
                'reprise_le' => now(),
                'statut' => $periode->statut === StatutPeriodePaie::BROUILLON ? StatutPeriodePaie::CALCULE : $periode->statut,
            ]);

            $description = "Transmission RH version {$transmission->version} reprise : {$totaux['nb_salaries']} salarié(s), "
                .'net à payer '.$this->format($totaux['net']).' GNF';
            if ($precedente !== null) {
                $description .= " (remplace la version {$precedente->version})";
            }

            $this->audit->record($periode, AuditEvent::UPDATED, $acteur,
                $precedente !== null ? ['transmission_version' => $precedente->version] : null,
                [
                    'transmission_version' => $transmission->version,
                    'nb_salaries' => $totaux['nb_salaries'],
                    'total_verse' => $totaux['verse'],
                    'total_avantages_nature' => $totaux['valorise'],
                    'total_net' => $totaux['net'],
                ],
                [
                    'module' => 'salaires',
                    'description' => $description,
                    'transmission_paie_id' => $transmission->id,
                ],
            );

            return $periode->fresh();
        });
    }

    private function exigerDroits(PaiePeriode $periode, User $acteur): void
    {
        // Gate::before du super administrateur : l'organisation est vérifiée ici explicitement.
        if ($acteur->organization_id === null || $acteur->organization_id !== $periode->organization_id) {
            throw new AuthorizationException;
        }
        if (! $acteur->can(self::PERMISSION)) {
            throw new AuthorizationException('Vous n\'avez pas le droit de préparer la paie.');
        }
        if (! $acteur->agitSurToutesLesAgences()) {
            throw new AuthorizationException('La transmission couvre les salariés de toutes les agences : sa reprise est réservée aux utilisateurs qui agissent sur toutes les agences.');
        }
    }

    /** Version demandée, relue sous verrou : seule la version transmise en vigueur du mois, jamais reprise. */
    private function versionAReprendre(PaiePeriode $periode, string $transmissionId): TransmissionPaie
    {
        $transmission = TransmissionPaie::whereKey($transmissionId)
            ->where('organization_id', $periode->organization_id)
            ->lockForUpdate()
            ->first();

        if ($transmission === null || $transmission->annee !== $periode->annee || $transmission->mois !== $periode->mois) {
            throw new InvalidArgumentException("Cette version de transmission ne concerne pas la paie de {$periode->labelPeriode()}.");
        }
        if ($transmission->statut === StatutTransmissionPaie::REMPLACEE) {
            throw new InvalidArgumentException("La version {$transmission->version} a été remplacée par une version plus récente : reprenez la version transmise en vigueur.");
        }
        if ($transmission->statut !== StatutTransmissionPaie::TRANSMISE) {
            throw new InvalidArgumentException("Seule une version transmise par les RH peut être reprise (version {$transmission->version} : {$transmission->statut->label()}).");
        }
        if ($transmission->prise_en_charge_le !== null || $periode->transmission_paie_id === $transmission->id) {
            throw new InvalidArgumentException("La version {$transmission->version} a déjà été reprise.");
        }

        return $transmission;
    }

    /**
     * Une ligne de paie par salarié : copie des montants du mois de la version, sans recalcul, puis
     * retenues de la Comptabilité (dépenses validées) et net.
     *
     * @param  Collection<int, TransmissionPaieLigne>  $elements
     */
    private function creerLignes(PaiePeriode $periode, Collection $elements): void
    {
        $parSalarie = $elements->groupBy('employe_id');

        $employesConnus = Employe::withTrashed()->where('organization_id', $periode->organization_id)
            ->whereIn('id', $parSalarie->keys())->pluck('id')->flip();
        $contratsConnus = Contrat::withTrashed()->where('organization_id', $periode->organization_id)
            ->whereIn('id', $elements->pluck('contrat_id')->filter()->unique())->pluck('id')->flip();

        foreach ($parSalarie as $employeId => $lignes) {
            if (! $employesConnus->has($employeId)) {
                throw new InvalidArgumentException("Le salarié {$lignes->first()->nom_salarie} de la transmission n'existe pas dans l'organisation : reprise impossible.");
            }

            $verse = $lignes->where('versement', TransmissionPaieLigne::VERSEMENT_VERSE);
            $contrats = $verse->where('origine', TransmissionPaieLigne::ORIGINE_CONTRAT);
            $salaireBase = round((float) $contrats->sum(fn (TransmissionPaieLigne $l) => (float) $l->montant), 2);
            $primes = round((float) $verse->where('origine', '!=', TransmissionPaieLigne::ORIGINE_CONTRAT)
                ->sum(fn (TransmissionPaieLigne $l) => (float) $l->montant), 2);
            $avantages = round((float) $lignes->where('versement', TransmissionPaieLigne::VERSEMENT_VALORISE)
                ->sum(fn (TransmissionPaieLigne $l) => (float) $l->montant), 2);
            $contratId = $contrats->sortBy(fn (TransmissionPaieLigne $l) => $l->au)->last()?->contrat_id;

            $ligne = PaieLigne::create([
                'paie_periode_id' => $periode->id,
                'employe_id' => $employeId,
                'contrat_id' => $contratId !== null && $contratsConnus->has($contratId) ? $contratId : null,
                'salaire_base' => $salaireBase,
                'jours_travailles' => (int) $contrats->sum('jours'),
                'jours_periode' => (int) $lignes->first()->jours_mois,
                'total_primes' => $primes,
                'total_autres_gains' => 0,
                'total_avantages_nature' => $avantages,
                'total_avances' => 0,
                'total_retenues' => 0,
                'total_absences' => 0,
                'total_autres_deductions' => 0,
                'brut' => round($salaireBase + $primes, 2),
                'deductions' => 0,
                'net' => round($salaireBase + $primes, 2),
                'deja_paye' => 0,
                'reste_a_payer' => round($salaireBase + $primes, 2),
                'statut' => StatutLignePaie::EN_ATTENTE,
            ]);

            $this->calcul->importerDepenses($ligne, $periode);
            $ligne->load('variables');
            $this->calcul->calculerLigne($ligne);
        }
    }

    /**
     * Les montants repris sont exactement ceux de la version : sinon tout est annulé.
     *
     * @return array{nb_salaries: int, verse: float, valorise: float, net: float}
     */
    private function verifierConcordance(PaiePeriode $periode, TransmissionPaie $transmission): array
    {
        $lignes = PaieLigne::where('paie_periode_id', $periode->id)->get();
        $verse = round((float) $lignes->sum(fn (PaieLigne $l) => (float) $l->salaire_base + (float) $l->total_primes), 2);
        $valorise = round((float) $lignes->sum(fn (PaieLigne $l) => (float) $l->total_avantages_nature), 2);

        if ($lignes->count() !== (int) $transmission->nb_salaries
            || abs($verse - (float) $transmission->total_verse) > 0.005
            || abs($valorise - (float) $transmission->total_valorise) > 0.005) {
            throw new RuntimeException("Écart entre la version {$transmission->version} et la paie reprise : reprise annulée.");
        }

        return [
            'nb_salaries' => $lignes->count(),
            'verse' => $verse,
            'valorise' => $valorise,
            'net' => round((float) $lignes->sum(fn (PaieLigne $l) => (float) $l->net), 2),
        ];
    }

    /** @return array<string, mixed> */
    private function resume(TransmissionPaie $transmission): array
    {
        return [
            'id' => $transmission->id,
            'version' => $transmission->version,
            'statut' => $transmission->statut->value,
            'nb_salaries' => $transmission->nb_salaries,
            'total_verse' => (float) $transmission->total_verse,
            'total_valorise' => (float) $transmission->total_valorise,
        ];
    }

    private function format(float $montant): string
    {
        return number_format($montant, 0, ',', "\u{202F}");
    }
}
