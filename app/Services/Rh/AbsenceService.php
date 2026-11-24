<?php

namespace App\Services\Rh;

use App\Enums\AuditEvent;
use App\Enums\StatutAbsence;
use App\Enums\StatutEmploye;
use App\Models\Absence;
use App\Models\Employe;
use App\Models\MotifAbsence;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Rh\PerimetreEmploye;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Seul point d'écriture des absences des salariés (docs/rh-absences.md, ADR 0070) : saisie,
 * modification, annulation. Contrôle ici, côté serveur, tout ce que l'écran ne fait qu'annoncer :
 * permission, organisation et agence du salarié (PerimetreEmploye), motif actif de l'organisation,
 * période d'emploi, chevauchement et doublon. Chaque écriture est journalisée (audit_logs).
 *
 * Aucune incidence sur la paie, les contrats ni les congés (non gérés en première version).
 */
class AbsenceService
{
    public const MODULE_AUDIT = 'rh_absences';

    /** Champs suivis par le journal d'audit (valeurs lisibles, cf. instantane()). */
    private const CHAMPS_JOURNAL = ['motif', 'date_debut', 'date_fin', 'duree_jours', 'commentaire', 'justificatif'];

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly JustificatifAbsenceStorage $stockage,
    ) {}

    // ── Droits (consultés aussi par les écrans pour n'afficher que les actions acceptées) ──────

    public static function peutConsulter(User $user, Absence $absence): bool
    {
        $employe = $absence->employe;

        return $user->can('rh-absences.read')
            && $absence->organization_id === $user->organization_id
            && $employe !== null
            && PerimetreEmploye::peutConsulter($user, $employe);
    }

    /** Saisir une absence pour ce salarié : permission + agence de rattachement. */
    public static function peutSaisirPour(User $user, Employe $employe): bool
    {
        return $user->can('rh-absences.create') && PerimetreEmploye::peutModifier($user, $employe);
    }

    public static function peutModifier(User $user, Absence $absence): bool
    {
        return ! $absence->estAnnulee()
            && $user->can('rh-absences.update')
            && $absence->employe !== null
            && PerimetreEmploye::peutModifier($user, $absence->employe);
    }

    public static function peutAnnuler(User $user, Absence $absence): bool
    {
        return ! $absence->estAnnulee()
            && $user->can('rh-absences.annuler')
            && $absence->employe !== null
            && PerimetreEmploye::peutModifier($user, $absence->employe);
    }

    // ── Écritures ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{employe_id: string, motif_absence_id: string, date_debut: string, date_fin: string, commentaire?: ?string}  $data
     */
    public function creer(array $data, ?UploadedFile $justificatif, User $acteur): Absence
    {
        $employe = $this->employeDeLOrganisation($data['employe_id'], $acteur);
        if (! self::peutSaisirPour($acteur, $employe)) {
            throw ValidationException::withMessages([
                'employe_id' => 'Ce salarié n\'est pas dans votre périmètre : vous ne pouvez pas lui saisir d\'absence.',
            ]);
        }

        $motif = $this->motifSaisissable($data['motif_absence_id'], $acteur->organization_id);
        [$debut, $fin] = $this->dates($data);
        $this->assertPeriodeEmploi($employe, $debut, $fin);

        // Identifiant connu avant l'écriture : le justificatif est rangé dans le dossier de l'absence.
        $id = strtolower((string) Str::ulid());
        $fichier = $justificatif ? $this->stockage->stocker($justificatif, $employe->organization_id, $employe->id, $id) : null;

        try {
            $absence = DB::transaction(function () use ($id, $employe, $motif, $debut, $fin, $data, $fichier, $acteur) {
                $this->verrouillerEtVerifierChevauchement($employe, $motif, $debut, $fin, null);

                $absence = new Absence([
                    'organization_id' => $employe->organization_id,
                    'employe_id' => $employe->id,
                    'motif_absence_id' => $motif->id,
                    'date_debut' => $debut->toDateString(),
                    'date_fin' => $fin->toDateString(),
                    'commentaire' => $this->commentaire($data['commentaire'] ?? null),
                    ...$this->colonnesJustificatif($fichier),
                    'statut' => StatutAbsence::ENREGISTREE->value,
                    'cree_par' => $acteur->id,
                ]);
                $absence->id = $id;
                $absence->save();

                $this->journaliser($absence, AuditEvent::CREATED, $acteur, null, $this->instantane($absence), 'Absence enregistrée');

                return $absence;
            });
        } catch (\Throwable $e) {
            $this->stockage->supprimer($fichier['path'] ?? null);
            throw $e;
        }

        return $absence;
    }

    /**
     * Le salarié ne change pas : une absence saisie sur le mauvais salarié s'annule et se ressaisit.
     *
     * @param  array{motif_absence_id: string, date_debut: string, date_fin: string, commentaire?: ?string}  $data
     */
    public function modifier(Absence $absence, array $data, ?UploadedFile $justificatif, bool $retirerJustificatif, User $acteur): Absence
    {
        $this->assertModifiable($absence, $acteur, 'rh-absences.update', 'modifier');

        $employe = $absence->employe;
        // Un motif désactivé depuis reste accepté s'il est déjà celui de l'absence.
        $motif = $data['motif_absence_id'] === $absence->motif_absence_id
            ? $absence->motif
            : $this->motifSaisissable($data['motif_absence_id'], $absence->organization_id);
        [$debut, $fin] = $this->dates($data);
        $this->assertPeriodeEmploi($employe, $debut, $fin);

        $nouveau = $justificatif ? $this->stockage->stocker($justificatif, $absence->organization_id, $employe->id, $absence->id) : null;
        $ancienChemin = $absence->justificatif_path;

        try {
            DB::transaction(function () use ($absence, $employe, $motif, $debut, $fin, $data, $nouveau, $retirerJustificatif, $acteur) {
                $this->verrouillerEtVerifierChevauchement($employe, $motif, $debut, $fin, $absence->id);
                // Relecture sous verrou : une annulation concurrente l'emporte.
                $absence->refresh();
                if ($absence->estAnnulee()) {
                    throw ValidationException::withMessages(['absence' => 'Cette absence vient d\'être annulée : elle ne peut plus être modifiée.']);
                }

                $avant = $this->instantane($absence);
                $absence->fill([
                    'motif_absence_id' => $motif->id,
                    'date_debut' => $debut->toDateString(),
                    'date_fin' => $fin->toDateString(),
                    'commentaire' => $this->commentaire($data['commentaire'] ?? null),
                    'modifie_par' => $acteur->id,
                ]);
                if ($nouveau !== null) {
                    $absence->fill($this->colonnesJustificatif($nouveau));
                } elseif ($retirerJustificatif) {
                    $absence->fill($this->colonnesJustificatif(null));
                }
                $absence->save();
                $absence->unsetRelation('motif');

                [$ancien, $apres] = $this->audit->diffFields($avant, $this->instantane($absence), self::CHAMPS_JOURNAL);
                if ($apres !== null) {
                    $this->journaliser($absence, AuditEvent::UPDATED, $acteur, $ancien, $apres, 'Absence modifiée');
                }
            });
        } catch (\Throwable $e) {
            $this->stockage->supprimer($nouveau['path'] ?? null);
            throw $e;
        }

        // Fichier remplacé ou retiré : supprimé seulement une fois l'écriture validée.
        if ($ancienChemin !== null && $ancienChemin !== $absence->justificatif_path) {
            $this->stockage->supprimer($ancienChemin);
        }

        return $absence;
    }

    /** Annulation logique : l'absence reste consultable (statut « Annulée »), jamais supprimée. */
    public function annuler(Absence $absence, ?string $motifAnnulation, User $acteur): Absence
    {
        $this->assertModifiable($absence, $acteur, 'rh-absences.annuler', 'annuler');

        return DB::transaction(function () use ($absence, $motifAnnulation, $acteur) {
            $absence = Absence::whereKey($absence->id)->lockForUpdate()->firstOrFail();
            if ($absence->estAnnulee()) {
                throw ValidationException::withMessages(['absence' => 'Cette absence est déjà annulée.']);
            }

            $motifAnnulation = $this->commentaire($motifAnnulation);
            $absence->update([
                'statut' => StatutAbsence::ANNULEE->value,
                'annule_par' => $acteur->id,
                'annule_le' => now(),
                'motif_annulation' => $motifAnnulation,
            ]);

            $this->journaliser(
                $absence,
                AuditEvent::CANCELLED,
                $acteur,
                ['statut' => StatutAbsence::ENREGISTREE->label()],
                array_filter(['statut' => StatutAbsence::ANNULEE->label(), 'motif_annulation' => $motifAnnulation]),
                'Absence annulée',
            );

            return $absence;
        });
    }

    // ── Contrôles ───────────────────────────────────────────────────────────────────────────

    private function employeDeLOrganisation(string $employeId, User $acteur): Employe
    {
        $employe = Employe::where('organization_id', $acteur->organization_id)->find($employeId);
        if ($employe === null) {
            throw ValidationException::withMessages(['employe_id' => 'Salarié introuvable.']);
        }

        return $employe;
    }

    private function motifSaisissable(string $motifId, string $organizationId): MotifAbsence
    {
        $motif = MotifAbsence::where('organization_id', $organizationId)->find($motifId);
        if ($motif === null) {
            throw ValidationException::withMessages(['motif_absence_id' => 'Motif introuvable.']);
        }
        if (! $motif->is_active) {
            throw ValidationException::withMessages(['motif_absence_id' => "Le motif « {$motif->libelle} » est désactivé : choisissez-en un autre."]);
        }

        return $motif;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function dates(array $data): array
    {
        $debut = CarbonImmutable::parse($data['date_debut'])->startOfDay();
        $fin = CarbonImmutable::parse($data['date_fin'])->startOfDay();

        if ($fin->lt($debut)) {
            throw ValidationException::withMessages([
                'date_fin' => 'La date de fin doit être postérieure ou égale à la date de début.',
            ]);
        }

        return [$debut, $fin];
    }

    /**
     * Le salarié doit être en poste sur toute la période : pas avant son embauche, pas après sa
     * sortie. Seules ces dates sont historisées : le statut « Suspendu » (sans dates) ne bloque pas.
     */
    private function assertPeriodeEmploi(Employe $employe, CarbonImmutable $debut, CarbonImmutable $fin): void
    {
        if ($employe->statut === StatutEmploye::SORTI && $employe->date_sortie === null) {
            throw ValidationException::withMessages(['employe_id' => 'Ce salarié est sorti : aucune absence ne peut lui être saisie.']);
        }
        if ($employe->date_embauche !== null && $debut->lt($employe->date_embauche)) {
            throw ValidationException::withMessages([
                'date_debut' => 'Le salarié n\'est embauché qu\'à partir du '.$employe->date_embauche->format('d/m/Y').'.',
            ]);
        }
        if ($employe->date_sortie !== null && $fin->gt($employe->date_sortie)) {
            throw ValidationException::withMessages([
                'date_fin' => 'Le salarié est sorti le '.$employe->date_sortie->format('d/m/Y').' : l\'absence ne peut pas aller au-delà.',
            ]);
        }
    }

    /**
     * Une seule absence enregistrée par jour et par salarié, quel que soit le motif. Appelé en
     * transaction, sous verrou du salarié : deux saisies simultanées ne passent pas toutes les deux.
     * L'identifiant de l'absence en conflit est renvoyé (`absence_existante`) pour que l'écran
     * propose de la consulter.
     */
    private function verrouillerEtVerifierChevauchement(Employe $employe, MotifAbsence $motif, CarbonImmutable $debut, CarbonImmutable $fin, ?string $ignorerId): void
    {
        Employe::whereKey($employe->id)->lockForUpdate()->first();

        $conflit = Absence::where('employe_id', $employe->id)
            ->enregistrees()
            ->chevauchant($debut, $fin)
            ->when($ignorerId !== null, fn ($q) => $q->whereKeyNot($ignorerId))
            ->with('motif')
            ->orderBy('date_debut')
            ->lockForUpdate()
            ->first();

        if ($conflit === null) {
            return;
        }

        $periode = 'du '.$conflit->date_debut->format('d/m/Y').' au '.$conflit->date_fin->format('d/m/Y');
        $libelleMotif = $conflit->motif?->libelle ?? '—';
        $doublon = $conflit->motif_absence_id === $motif->id
            && $conflit->date_debut->isSameDay($debut)
            && $conflit->date_fin->isSameDay($fin);

        throw ValidationException::withMessages([
            'chevauchement' => $doublon
                ? "Cette absence est déjà enregistrée ({$libelleMotif}, {$periode}) : doublon refusé."
                : "Ce salarié a déjà une absence sur cette période ({$libelleMotif}, {$periode}). Modifiez l'absence existante ou choisissez d'autres dates.",
            'absence_existante' => $conflit->id,
        ]);
    }

    /**
     * Droits d'abord (permission, organisation, agence de rattachement), statut ensuite : un
     * utilisateur hors périmètre reçoit toujours un 403, sans rien apprendre de l'absence.
     */
    private function assertModifiable(Absence $absence, User $acteur, string $permission, string $verbe): void
    {
        abort_unless(
            $acteur->can($permission) && $absence->employe !== null && PerimetreEmploye::peutModifier($acteur, $absence->employe),
            403,
        );
        if ($absence->estAnnulee()) {
            throw ValidationException::withMessages(['absence' => 'Cette absence est annulée : elle ne peut plus être '.($verbe === 'annuler' ? 'annulée de nouveau' : 'modifiée').'.']);
        }
    }

    private function commentaire(?string $commentaire): ?string
    {
        $commentaire = trim((string) $commentaire);

        return $commentaire === '' ? null : $commentaire;
    }

    /**
     * @param  array{path: string, nom_original: string, mime_type: string, taille: int}|null  $fichier
     * @return array<string, mixed>
     */
    private function colonnesJustificatif(?array $fichier): array
    {
        return [
            'justificatif_path' => $fichier['path'] ?? null,
            'justificatif_nom_original' => $fichier['nom_original'] ?? null,
            'justificatif_mime_type' => $fichier['mime_type'] ?? null,
            'justificatif_taille' => $fichier['taille'] ?? null,
        ];
    }

    // ── Journal ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function instantane(Absence $absence): array
    {
        $absence->loadMissing('motif');

        return [
            'motif' => $absence->motif?->libelle,
            'date_debut' => $absence->date_debut?->format('d/m/Y'),
            'date_fin' => $absence->date_fin?->format('d/m/Y'),
            'duree_jours' => $absence->duree(),
            'commentaire' => $absence->commentaire,
            'justificatif' => $absence->justificatif_nom_original,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $avant
     * @param  array<string, mixed>|null  $apres
     */
    private function journaliser(Absence $absence, AuditEvent $evenement, User $acteur, ?array $avant, ?array $apres, string $description): void
    {
        $absence->loadMissing('employe.personne');

        $this->audit->record($absence, $evenement, $acteur, $avant, $apres, [
            'module' => self::MODULE_AUDIT,
            'description' => $description,
            'salarie' => $absence->employe?->nom_complet,
            'site_id' => $absence->employe?->site_id,
        ]);
    }
}
