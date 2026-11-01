<?php

namespace App\Services\Rh;

use App\Enums\AuditEvent;
use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\PeriodiciteRemuneration;
use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutEmploye;
use App\Enums\StatutSaisieRemuneration;
use App\Enums\StatutTypeRemuneration;
use App\Models\AffectationRemuneration;
use App\Models\Employe;
use App\Models\SaisieRemuneration;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Rh\Paie\CalendrierPaieService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seul point d'écriture des affectations de primes et d'avantages aux salariés
 * (docs/primes-avantages.md, ADR 0039).
 *
 * - Le droit de validation (`rh-affectations-remuneration.valider`) est contrôlé ICI, en plus des
 *   contrôleurs ; l'organisation et l'agence du salarié aussi (le Gate::before du super
 *   administrateur ne les vérifie pas).
 * - Chaque écriture se fait en transaction, sous verrou du salarié : deux affectations
 *   simultanées du même type ne peuvent pas toutes les deux passer le contrôle de chevauchement.
 * - L'historique n'est jamais réécrit : un changement de montant crée une affectation qui succède
 *   à la précédente, une fin d'effet se demande puis se valide, et aucun mois figé
 *   (CalendrierPaieService) ne peut être touché.
 * - Les règles sont revérifiées au moment de la validation (le type a pu être fermé, le mois figé).
 */
class AffectationRemunerationService
{
    public const PERMISSION_VALIDATION = 'rh-affectations-remuneration.valider';

    private const MODULE_AUDIT = 'rh_affectations_remuneration';

    private const CHAMPS_JOURNAL = [
        'date_debut', 'date_fin', 'montant', 'taux', 'commentaire', 'statut', 'date_fin_demandee', 'motif_fin',
    ];

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly CalendrierPaieService $calendrier,
    ) {}

    // ── Affectation ─────────────────────────────────────────────────────────────

    /**
     * Attribue un type validé à un salarié : « à valider », ou validée directement si l'auteur le
     * demande et en a le droit.
     *
     * @param  array<string, mixed>  $data  données validées par AffectationRemunerationRequest
     */
    public function affecter(array $data, User $acteur, bool $valider = false): AffectationRemuneration
    {
        $organizationId = $this->organisationDe($acteur);
        if ($valider) {
            $this->exigerDroitValidation($acteur);
        }

        return DB::transaction(function () use ($data, $acteur, $organizationId, $valider) {
            $employe = $this->verrouillerEmploye((string) $data['employe_id'], $organizationId);
            $this->assertPeutAgirSur($employe, $acteur);

            $type = TypeRemuneration::where('organization_id', $organizationId)->find($data['type_remuneration_id']);
            if ($type === null) {
                throw ValidationException::withMessages(['type_remuneration_id' => 'Type de prime ou d\'avantage inconnu.']);
            }

            [$debut, $fin] = $this->periode($type, $data);
            $this->assertTypeAttribuable($type, $debut, $fin, false);
            $valeurs = $this->valeurs($type, $data);
            $this->assertPeriodeValide($employe, $debut, $fin);
            $this->assertSansChevauchement($employe, $type, $debut, $fin, []);

            $affectation = AffectationRemuneration::create([
                'organization_id' => $organizationId,
                'employe_id' => $employe->id,
                'type_remuneration_id' => $type->id,
                'date_debut' => $debut->toDateString(),
                'date_fin' => $fin?->toDateString(),
                ...$valeurs,
                'commentaire' => $this->texte($data['commentaire'] ?? null),
                'statut' => StatutAffectationRemuneration::A_VALIDER->value,
                'cree_par' => $acteur->id,
                'modifie_par' => $acteur->id,
            ]);

            $this->journaliser($affectation, AuditEvent::CREATED, $acteur, null, $this->instantane($affectation),
                "« {$type->libelle} » attribué à {$employe->nom_complet} à partir du {$debut->format('d/m/Y')}");

            if ($valider) {
                $this->appliquerValidation($affectation, $acteur);
            }

            return $affectation;
        });
    }

    /**
     * Modifie une proposition encore « à valider » (dates, montant, commentaire). Une affectation
     * validée ne se modifie plus : on change son montant à partir d'une date, ou on y met fin.
     *
     * @param  array<string, mixed>  $data
     */
    public function modifier(AffectationRemuneration $affectation, array $data, User $acteur, bool $valider = false): AffectationRemuneration
    {
        $this->assertMemeOrganisation($affectation, $acteur);
        if ($valider) {
            $this->exigerDroitValidation($acteur);
        }

        return DB::transaction(function () use ($affectation, $data, $acteur, $valider) {
            $employe = $this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id);
            $this->assertPeutAgirSur($employe, $acteur);
            $affectation = $this->verrouiller($affectation);

            if ($affectation->statut !== StatutAffectationRemuneration::A_VALIDER) {
                throw ValidationException::withMessages([
                    'affectation' => 'Cette affectation n\'est plus une proposition : changez son montant à partir d\'une date ou mettez-y fin.',
                ]);
            }

            $type = $affectation->type;
            [$debut, $fin] = $this->periode($type, $data);
            if ($affectation->remplace_affectation_id !== null) {
                $this->assertDateEffetChangement($affectation->remplace, $debut);
            }
            $this->assertTypeAttribuable($type, $debut, $fin, $affectation->remplace_affectation_id !== null);
            $valeurs = $this->valeurs($type, $data);
            $this->assertPeriodeValide($employe, $debut, $fin);
            $this->assertSansChevauchement($employe, $type, $debut, $fin, array_filter([$affectation->id, $affectation->remplace_affectation_id]));

            $avant = $this->instantane($affectation);
            $affectation->update([
                'date_debut' => $debut->toDateString(),
                'date_fin' => $fin?->toDateString(),
                ...$valeurs,
                'commentaire' => $this->texte($data['commentaire'] ?? null),
                'modifie_par' => $acteur->id,
            ]);
            [$ancien, $nouveau] = $this->audit->diffFields($avant, $this->instantane($affectation), self::CHAMPS_JOURNAL);
            if ($nouveau !== null) {
                $this->journaliser($affectation, AuditEvent::UPDATED, $acteur, $ancien, $nouveau, 'Proposition modifiée');
            }

            if ($valider) {
                $this->appliquerValidation($affectation, $acteur);
            }

            return $affectation;
        });
    }

    /**
     * Nouveau montant (ou taux) à partir d'une date : une nouvelle affectation succède à la
     * précédente, qui s'arrêtera la veille une fois le changement validé. Les périodes écoulées
     * gardent leur montant.
     *
     * @param  array<string, mixed>  $data  date_effet, montant ou taux, date_fin facultative, commentaire
     */
    public function changerMontant(AffectationRemuneration $affectation, array $data, User $acteur, bool $valider = false): AffectationRemuneration
    {
        $this->assertMemeOrganisation($affectation, $acteur);
        if ($valider) {
            $this->exigerDroitValidation($acteur);
        }

        return DB::transaction(function () use ($affectation, $data, $acteur, $valider) {
            $employe = $this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id);
            $this->assertPeutAgirSur($employe, $acteur);
            $affectation = $this->verrouiller($affectation);
            $type = $affectation->type;

            $this->assertValideeSansDemandeEnCours($affectation);
            if ($type->periodicite === PeriodiciteRemuneration::PONCTUELLE) {
                throw ValidationException::withMessages(['affectation' => 'Prime ponctuelle : annulez-la et attribuez-en une nouvelle.']);
            }
            if ($type->nature_montant === NatureMontantRemuneration::VARIABLE) {
                throw ValidationException::withMessages(['affectation' => 'Ce type est saisi chaque mois : modifiez le montant du mois concerné.']);
            }

            $effet = Carbon::parse((string) $data['date_effet'])->startOfDay();
            $this->assertDateEffetChangement($affectation, $effet);
            $fin = filled($data['date_fin'] ?? null) ? Carbon::parse((string) $data['date_fin'])->startOfDay() : $affectation->date_fin?->copy();
            if ($fin !== null && $fin->lt($effet)) {
                throw ValidationException::withMessages(['date_fin' => 'La fin d\'effet doit être postérieure ou égale à la date du changement.']);
            }

            $this->assertTypeAttribuable($type, $effet, $fin, true);
            $valeurs = $this->valeurs($type, $data);
            if ((float) $valeurs['montant'] === (float) $affectation->montant && (float) $valeurs['taux'] === (float) $affectation->taux) {
                throw ValidationException::withMessages([
                    $type->mode_calcul === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE ? 'taux' : 'montant' => 'Le nouveau montant est identique au montant actuel.',
                ]);
            }
            $this->assertPeriodeValide($employe, $effet, $fin);
            $this->assertSansChevauchement($employe, $type, $effet, $fin, [$affectation->id]);

            $successeur = AffectationRemuneration::create([
                'organization_id' => $affectation->organization_id,
                'employe_id' => $affectation->employe_id,
                'type_remuneration_id' => $type->id,
                'date_debut' => $effet->toDateString(),
                'date_fin' => $fin?->toDateString(),
                ...$valeurs,
                'commentaire' => $this->texte($data['commentaire'] ?? null),
                'statut' => StatutAffectationRemuneration::A_VALIDER->value,
                'remplace_affectation_id' => $affectation->id,
                'cree_par' => $acteur->id,
                'modifie_par' => $acteur->id,
            ]);

            $this->journaliser($successeur, AuditEvent::CREATED, $acteur,
                ['montant' => $affectation->montant, 'taux' => $affectation->taux],
                $this->instantane($successeur),
                "Changement de montant de « {$type->libelle} » à partir du {$effet->format('d/m/Y')}");

            if ($valider) {
                $this->appliquerValidation($successeur, $acteur);
            }

            return $successeur;
        });
    }

    /**
     * Demande de fin d'effet d'une affectation validée (retrait individuel). Le type reste
     * attribuable aux autres salariés. Appliquée à sa validation.
     *
     * @param  array<string, mixed>  $data  date_fin, motif
     */
    public function demanderFin(AffectationRemuneration $affectation, array $data, User $acteur, bool $valider = false): AffectationRemuneration
    {
        $this->assertMemeOrganisation($affectation, $acteur);
        if ($valider) {
            $this->exigerDroitValidation($acteur);
        }

        return DB::transaction(function () use ($affectation, $data, $acteur, $valider) {
            $employe = $this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id);
            $this->assertPeutAgirSur($employe, $acteur);
            $affectation = $this->verrouiller($affectation);

            $this->assertValideeSansDemandeEnCours($affectation);
            if ($affectation->type->periodicite === PeriodiciteRemuneration::PONCTUELLE) {
                throw ValidationException::withMessages(['affectation' => 'Prime ponctuelle : annulez-la plutôt que d\'y mettre fin.']);
            }

            $fin = Carbon::parse((string) $data['date_fin'])->startOfDay();
            if ($fin->lt($affectation->date_debut)) {
                throw ValidationException::withMessages([
                    'date_fin' => 'La fin d\'effet ne peut pas précéder le début ('.$affectation->date_debut->format('d/m/Y').'). Pour une affectation qui n\'a pas commencé, annulez-la.',
                ]);
            }
            if ($affectation->date_fin !== null && $fin->gte($affectation->date_fin)) {
                throw ValidationException::withMessages([
                    'date_fin' => 'La fin d\'effet doit précéder la fin actuelle ('.$affectation->date_fin->format('d/m/Y').').',
                ]);
            }
            $this->calendrier->exigerModifiable($affectation->organization_id, $fin->copy()->addDay(), 'date_fin', 'une fin d\'effet arrête les versements');

            $affectation->update([
                'date_fin_demandee' => $fin->toDateString(),
                'motif_fin' => $this->texte($data['motif'] ?? null),
                'fin_demandee_par' => $acteur->id,
                'fin_demandee_le' => now(),
                'modifie_par' => $acteur->id,
            ]);
            $this->journaliser($affectation, AuditEvent::SUBMITTED, $acteur, null,
                ['date_fin_demandee' => $fin->toDateString(), 'motif_fin' => $affectation->motif_fin],
                "Fin d'effet demandée au {$fin->format('d/m/Y')}");

            if ($valider) {
                $this->appliquerFin($affectation, $acteur);
            }

            return $affectation;
        });
    }

    public function retirerDemandeFin(AffectationRemuneration $affectation, User $acteur): AffectationRemuneration
    {
        $this->assertMemeOrganisation($affectation, $acteur);

        return DB::transaction(function () use ($affectation, $acteur) {
            $this->assertPeutAgirSur($this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id), $acteur);
            $affectation = $this->verrouiller($affectation);
            if ($affectation->date_fin_demandee === null) {
                throw ValidationException::withMessages(['affectation' => 'Aucune fin d\'effet n\'est demandée.']);
            }

            $avant = ['date_fin_demandee' => $affectation->date_fin_demandee->toDateString(), 'motif_fin' => $affectation->motif_fin];
            $affectation->update([
                'date_fin_demandee' => null, 'motif_fin' => null, 'fin_demandee_par' => null, 'fin_demandee_le' => null,
                'modifie_par' => $acteur->id,
            ]);
            $this->journaliser($affectation, AuditEvent::UPDATED, $acteur, $avant, ['date_fin_demandee' => null], 'Demande de fin d\'effet retirée');

            return $affectation;
        });
    }

    /** Valide ce qui attend : la proposition elle-même, ou la fin d'effet demandée. */
    public function valider(AffectationRemuneration $affectation, User $acteur): AffectationRemuneration
    {
        $this->assertMemeOrganisation($affectation, $acteur);
        $this->exigerDroitValidation($acteur);

        return DB::transaction(function () use ($affectation, $acteur) {
            $this->assertPeutAgirSur($this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id), $acteur);
            $affectation = $this->verrouiller($affectation);

            return match (true) {
                $affectation->statut === StatutAffectationRemuneration::A_VALIDER => $this->appliquerValidation($affectation, $acteur),
                $affectation->statut === StatutAffectationRemuneration::VALIDEE && $affectation->date_fin_demandee !== null => $this->appliquerFin($affectation, $acteur),
                default => throw ValidationException::withMessages(['statut' => 'Rien n\'attend de validation sur cette affectation.']),
            };
        });
    }

    /**
     * Annule une proposition, ou une affectation validée jamais transmise et pas encore figée
     * (droit de validation requis). L'affectation reste dans l'historique, statut « annulée ».
     */
    public function annuler(AffectationRemuneration $affectation, ?string $motif, User $acteur): AffectationRemuneration
    {
        $this->assertMemeOrganisation($affectation, $acteur);

        return DB::transaction(function () use ($affectation, $motif, $acteur) {
            $this->assertPeutAgirSur($this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id), $acteur);
            $affectation = $this->verrouiller($affectation);

            if ($affectation->statut === StatutAffectationRemuneration::ANNULEE) {
                throw ValidationException::withMessages(['statut' => 'Cette affectation est déjà annulée.']);
            }

            if ($affectation->statut === StatutAffectationRemuneration::VALIDEE) {
                $this->exigerDroitValidation($acteur, 'Cette affectation est validée : seule une personne habilitée à valider peut l\'annuler.');
                if ($affectation->successeur()->exists()) {
                    throw ValidationException::withMessages(['statut' => 'Un changement de montant lui succède : annulez d\'abord ce changement.']);
                }
                if ($affectation->estTransmise()) {
                    throw ValidationException::withMessages(['statut' => 'Cette affectation a déjà été transmise à la Comptabilité : mettez-y fin plutôt que de l\'annuler.']);
                }
                $this->calendrier->exigerModifiable($affectation->organization_id, $affectation->date_debut, 'statut', 'une annulation s\'applique');
            }

            $avant = $this->instantane($affectation);
            $affectation->update([
                'statut' => StatutAffectationRemuneration::ANNULEE->value,
                'motif_annulation' => $this->texte($motif),
                'annule_par' => $acteur->id,
                'annule_le' => now(),
                'date_fin_demandee' => null,
                'fin_demandee_par' => null,
                'fin_demandee_le' => null,
                'modifie_par' => $acteur->id,
            ]);
            $this->journaliser($affectation, AuditEvent::CANCELLED, $acteur, ['statut' => $avant['statut']],
                ['statut' => StatutAffectationRemuneration::ANNULEE->value, 'motif_annulation' => $affectation->motif_annulation],
                'Affectation annulée'.($affectation->motif_annulation ? " : {$affectation->motif_annulation}" : ''));

            // Un changement de montant validé puis annulé : l'affectation précédente reprend sa fin d'origine.
            if ($avant['statut'] === StatutAffectationRemuneration::VALIDEE->value && $affectation->remplace_affectation_id !== null) {
                $precedente = $this->verrouiller($affectation->remplace);
                $finAvant = $precedente->date_fin?->toDateString();
                $precedente->update(['date_fin' => $affectation->date_fin?->toDateString(), 'modifie_par' => $acteur->id]);
                $this->journaliser($precedente, AuditEvent::UPDATED, $acteur, ['date_fin' => $finAvant],
                    ['date_fin' => $precedente->date_fin?->toDateString()], 'Changement de montant annulé : fin d\'effet rétablie');
            }

            return $affectation;
        });
    }

    // ── Montants saisis chaque mois ─────────────────────────────────────────────

    /**
     * Saisit (ou corrige) le montant d'un mois pour un élément « saisi chaque mois ». Un montant
     * déjà validé ne se corrige qu'avec le droit de validation ; un mois figé ne se touche plus.
     *
     * @param  array<string, mixed>  $data  annee, mois, montant ou taux, commentaire
     */
    public function enregistrerSaisie(AffectationRemuneration $affectation, array $data, User $acteur, bool $valider = false): SaisieRemuneration
    {
        $this->assertMemeOrganisation($affectation, $acteur);
        if ($valider) {
            $this->exigerDroitValidation($acteur);
        }

        return DB::transaction(function () use ($affectation, $data, $acteur, $valider) {
            $this->assertPeutAgirSur($this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id), $acteur);
            $affectation = $this->verrouiller($affectation);
            $type = $affectation->type;

            if ($affectation->statut !== StatutAffectationRemuneration::VALIDEE || $type->nature_montant !== NatureMontantRemuneration::VARIABLE) {
                throw ValidationException::withMessages(['mois' => 'Seule une affectation validée d\'un élément « saisi chaque mois » reçoit un montant mensuel.']);
            }

            $annee = (int) $data['annee'];
            $mois = (int) $data['mois'];
            $debutMois = Carbon::create($annee, $mois, 1)->startOfDay();
            $finMois = $debutMois->copy()->endOfMonth()->startOfDay();
            if ($affectation->date_debut->gt($finMois) || ($affectation->date_fin !== null && $affectation->date_fin->lt($debutMois))) {
                throw ValidationException::withMessages(['mois' => 'L\'affectation n\'est pas en vigueur sur ce mois.']);
            }
            $this->calendrier->exigerModifiable($affectation->organization_id, $debutMois, 'mois', 'un montant mensuel se saisit');

            $pourcentage = $type->mode_calcul === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE;
            $valeur = $pourcentage ? ($data['taux'] ?? null) : ($data['montant'] ?? null);
            if ($valeur === null || $valeur === '') {
                throw ValidationException::withMessages([$pourcentage ? 'taux' : 'montant' => $pourcentage ? 'Le taux du mois est obligatoire.' : 'Le montant du mois est obligatoire.']);
            }

            $saisie = SaisieRemuneration::where('affectation_id', $affectation->id)
                ->where('annee', $annee)->where('mois', $mois)
                ->lockForUpdate()->first();
            if ($saisie?->statut === StatutSaisieRemuneration::VALIDEE) {
                $this->exigerDroitValidation($acteur, 'Ce montant a déjà été validé : seule une personne habilitée à valider peut le corriger.');
            }

            $attributs = [
                'montant' => $pourcentage ? null : $valeur,
                'taux' => $pourcentage ? $valeur : null,
                'commentaire' => $this->texte($data['commentaire'] ?? null),
            ];
            $libelleMois = sprintf('%02d/%d', $mois, $annee);

            if ($saisie === null) {
                $saisie = SaisieRemuneration::create([
                    'organization_id' => $affectation->organization_id,
                    'affectation_id' => $affectation->id,
                    'annee' => $annee,
                    'mois' => $mois,
                    ...$attributs,
                    'statut' => StatutSaisieRemuneration::A_VALIDER->value,
                    'saisi_par' => $acteur->id,
                ]);
                $this->journaliser($affectation, AuditEvent::CREATED, $acteur, null,
                    ['mois' => $libelleMois, 'montant' => $saisie->montant, 'taux' => $saisie->taux], "Montant de {$libelleMois} saisi");
            } else {
                $avant = ['montant' => $saisie->montant, 'taux' => $saisie->taux];
                $saisie->update([...$attributs, 'saisi_par' => $acteur->id]);
                $this->journaliser($affectation, AuditEvent::UPDATED, $acteur, $avant,
                    ['mois' => $libelleMois, 'montant' => $saisie->montant, 'taux' => $saisie->taux], "Montant de {$libelleMois} corrigé");
            }

            if ($valider && $saisie->statut === StatutSaisieRemuneration::A_VALIDER) {
                $this->appliquerValidationSaisie($saisie, $affectation, $acteur);
            }

            return $saisie;
        });
    }

    public function validerSaisie(SaisieRemuneration $saisie, User $acteur): SaisieRemuneration
    {
        $affectation = $saisie->affectation;
        $this->assertMemeOrganisation($affectation, $acteur);
        $this->exigerDroitValidation($acteur);

        return DB::transaction(function () use ($saisie, $affectation, $acteur) {
            $this->assertPeutAgirSur($this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id), $acteur);
            $saisie = SaisieRemuneration::whereKey($saisie->id)->lockForUpdate()->firstOrFail();
            if ($saisie->statut !== StatutSaisieRemuneration::A_VALIDER) {
                throw ValidationException::withMessages(['statut' => 'Ce montant est déjà validé.']);
            }
            $this->calendrier->exigerModifiable($affectation->organization_id, Carbon::create($saisie->annee, $saisie->mois, 1), 'mois', 'un montant mensuel se valide');

            return $this->appliquerValidationSaisie($saisie, $affectation, $acteur);
        });
    }

    public function supprimerSaisie(SaisieRemuneration $saisie, User $acteur): void
    {
        $affectation = $saisie->affectation;
        $this->assertMemeOrganisation($affectation, $acteur);

        DB::transaction(function () use ($saisie, $affectation, $acteur) {
            $this->assertPeutAgirSur($this->verrouillerEmploye($affectation->employe_id, $affectation->organization_id), $acteur);
            $saisie = SaisieRemuneration::whereKey($saisie->id)->lockForUpdate()->firstOrFail();
            if ($saisie->statut === StatutSaisieRemuneration::VALIDEE) {
                $this->exigerDroitValidation($acteur, 'Ce montant a déjà été validé : seule une personne habilitée à valider peut le supprimer.');
            }
            $this->calendrier->exigerModifiable($affectation->organization_id, Carbon::create($saisie->annee, $saisie->mois, 1), 'mois', 'un montant mensuel se supprime');

            $this->journaliser($affectation, AuditEvent::DELETED, $acteur,
                ['mois' => $saisie->periodeLabel(), 'montant' => $saisie->montant, 'taux' => $saisie->taux], null,
                "Montant de {$saisie->periodeLabel()} supprimé");
            $saisie->delete();
        });
    }

    // ── Désactivation globale d'un type ──────────────────────────────────────────

    /** Affectations validées encore ouvertes à partir de ce jour (bénéficiaires actuels ou à venir). */
    public function nbBeneficiairesOuverts(TypeRemuneration $type, Carbon $jour): int
    {
        return AffectationRemuneration::where('type_remuneration_id', $type->id)
            ->where('statut', StatutAffectationRemuneration::VALIDEE->value)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $jour->toDateString()))
            ->count();
    }

    /**
     * Désactivation d'un type à une date d'effet (ADR 0039, appelée par TypeRemunerationService
     * dans sa transaction) : chaque affectation validée encore ouverte s'arrête la veille, une à
     * une, avec un motif et une trace ; une affectation qui n'aurait commencé qu'après est annulée ;
     * les propositions en attente sont annulées. Rien n'est jamais repris automatiquement à la
     * réactivation du type.
     *
     * @return array{terminees: int, annulees: int}
     */
    public function arreterPourDesactivation(TypeRemuneration $type, Carbon $effet, User $acteur): array
    {
        $this->calendrier->exigerModifiable($type->organization_id, $effet, 'date_effet', 'une désactivation prend effet');
        $motif = 'Désactivation du type au '.$effet->format('d/m/Y');
        $terminees = 0;
        $annulees = 0;

        $affectations = AffectationRemuneration::where('type_remuneration_id', $type->id)
            ->whereIn('statut', StatutAffectationRemuneration::occupantLaPeriode())
            ->lockForUpdate()
            ->get();

        foreach ($affectations as $affectation) {
            $avant = $this->instantane($affectation);
            $ouverte = $affectation->date_fin === null || $affectation->date_fin->gte($effet);

            if ($affectation->statut === StatutAffectationRemuneration::A_VALIDER || $affectation->date_debut->gte($effet)) {
                $affectation->update([
                    'statut' => StatutAffectationRemuneration::ANNULEE->value,
                    'motif_annulation' => $motif,
                    'annule_par' => $acteur->id,
                    'annule_le' => now(),
                    'date_fin_demandee' => null, 'fin_demandee_par' => null, 'fin_demandee_le' => null,
                    'modifie_par' => $acteur->id,
                ]);
                $this->journaliser($affectation, AuditEvent::CANCELLED, $acteur, ['statut' => $avant['statut']],
                    ['statut' => StatutAffectationRemuneration::ANNULEE->value], $motif);
                $annulees++;

                continue;
            }

            if ($ouverte) {
                $affectation->update([
                    'date_fin' => $effet->copy()->subDay()->toDateString(),
                    'motif_fin' => $motif,
                    'date_fin_demandee' => null, 'fin_demandee_par' => null, 'fin_demandee_le' => null,
                    'modifie_par' => $acteur->id,
                ]);
                $this->journaliser($affectation, AuditEvent::ENDED, $acteur, ['date_fin' => $avant['date_fin']],
                    ['date_fin' => $affectation->date_fin->toDateString(), 'motif_fin' => $motif], $motif);
                $terminees++;
            }
        }

        return ['terminees' => $terminees, 'annulees' => $annulees];
    }

    // ── Contrôles ───────────────────────────────────────────────────────────────

    /** Écriture : salarié de l'organisation ET rattaché à l'une des agences de l'auteur (sauf périmètre toutes agences). */
    public function assertPeutAgirSur(Employe $employe, User $acteur): void
    {
        if ($employe->organization_id !== $this->organisationDe($acteur)) {
            throw new AuthorizationException;
        }
        if ($acteur->agitSurToutesLesAgences()) {
            return;
        }
        if ($employe->site_id === null || ! $acteur->sites()->whereKey($employe->site_id)->exists()) {
            throw new AuthorizationException('Ce salarié n\'est pas rattaché à l\'une de vos agences.');
        }
    }

    /** Consultation : agences consultables (vision 360° comprise, ADR 0025). */
    public static function peutConsulter(Employe $employe, User $user): bool
    {
        if ($employe->organization_id !== $user->organization_id) {
            return false;
        }
        if ($user->voitToutesLesAgences()) {
            return true;
        }

        return $employe->site_id !== null && $user->sites()->whereKey($employe->site_id)->exists();
    }

    private function appliquerValidation(AffectationRemuneration $affectation, User $acteur): AffectationRemuneration
    {
        $employe = $affectation->employe;
        $type = TypeRemuneration::whereKey($affectation->type_remuneration_id)->firstOrFail();
        $debut = $affectation->date_debut->copy();
        $fin = $affectation->date_fin?->copy();
        $succession = $affectation->remplace_affectation_id !== null;

        $this->assertTypeAttribuable($type, $debut, $fin, $succession);
        $this->assertPeriodeValide($employe, $debut, $fin);
        $this->assertSansChevauchement($employe, $type, $debut, $fin, array_filter([$affectation->id, $affectation->remplace_affectation_id]));

        if ($succession) {
            $precedente = $this->verrouiller($affectation->remplace);
            $this->assertDateEffetChangement($precedente, $debut);
            $finAvant = $precedente->date_fin?->toDateString();
            $precedente->update(['date_fin' => $debut->copy()->subDay()->toDateString(), 'modifie_par' => $acteur->id]);
            $this->journaliser($precedente, AuditEvent::ENDED, $acteur, ['date_fin' => $finAvant],
                ['date_fin' => $precedente->date_fin->toDateString()],
                "Remplacée à partir du {$debut->format('d/m/Y')} par un nouveau montant");
        }

        $affectation->update([
            'statut' => StatutAffectationRemuneration::VALIDEE->value,
            'valide_par' => $acteur->id,
            'valide_le' => now(),
            'modifie_par' => $acteur->id,
        ]);
        $this->journaliser($affectation, AuditEvent::VALIDATED, $acteur,
            ['statut' => StatutAffectationRemuneration::A_VALIDER->value], ['statut' => StatutAffectationRemuneration::VALIDEE->value],
            $succession ? 'Changement de montant validé' : 'Affectation validée');

        return $affectation;
    }

    private function appliquerFin(AffectationRemuneration $affectation, User $acteur): AffectationRemuneration
    {
        $fin = $affectation->date_fin_demandee->copy();
        $this->calendrier->exigerModifiable($affectation->organization_id, $fin->copy()->addDay(), 'date_fin', 'une fin d\'effet arrête les versements');

        $finAvant = $affectation->date_fin?->toDateString();
        $affectation->update([
            'date_fin' => $fin->toDateString(),
            'date_fin_demandee' => null,
            'fin_demandee_par' => null,
            'fin_demandee_le' => null,
            'modifie_par' => $acteur->id,
        ]);
        $this->journaliser($affectation, AuditEvent::ENDED, $acteur, ['date_fin' => $finAvant],
            ['date_fin' => $fin->toDateString(), 'motif_fin' => $affectation->motif_fin],
            "Fin d'effet validée au {$fin->format('d/m/Y')}".($affectation->motif_fin ? " : {$affectation->motif_fin}" : ''));

        return $affectation;
    }

    private function appliquerValidationSaisie(SaisieRemuneration $saisie, AffectationRemuneration $affectation, User $acteur): SaisieRemuneration
    {
        $saisie->update([
            'statut' => StatutSaisieRemuneration::VALIDEE->value,
            'valide_par' => $acteur->id,
            'valide_le' => now(),
        ]);
        $this->journaliser($affectation, AuditEvent::VALIDATED, $acteur, null,
            ['mois' => $saisie->periodeLabel(), 'montant' => $saisie->montant, 'taux' => $saisie->taux],
            "Montant de {$saisie->periodeLabel()} validé");

        return $saisie;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private function periode(TypeRemuneration $type, array $data): array
    {
        $debut = Carbon::parse((string) $data['date_debut'])->startOfDay();

        // Prime ponctuelle : un seul mois, du premier au dernier jour.
        if ($type->periodicite === PeriodiciteRemuneration::PONCTUELLE) {
            return [$debut->copy()->startOfMonth(), $debut->copy()->endOfMonth()->startOfDay()];
        }

        $fin = filled($data['date_fin'] ?? null) ? Carbon::parse((string) $data['date_fin'])->startOfDay() : null;
        if ($fin !== null && $fin->lt($debut)) {
            throw ValidationException::withMessages(['date_fin' => 'La fin d\'effet doit être postérieure ou égale au début.']);
        }

        return [$debut, $fin];
    }

    /**
     * Un type « à valider » ou désactivé n'est jamais attribuable ; un type fermé accepte encore
     * le changement de montant d'un bénéficiaire actuel, jamais une nouvelle attribution. Les
     * dates restent dans la période d'application du type.
     */
    private function assertTypeAttribuable(TypeRemuneration $type, Carbon $debut, ?Carbon $fin, bool $succession): void
    {
        $refus = match (true) {
            $type->statut === StatutTypeRemuneration::A_VALIDER => 'Ce type n\'est pas encore validé : il ne peut pas être attribué.',
            $type->statut === StatutTypeRemuneration::INACTIF => 'Ce type est désactivé : il ne peut plus être attribué.',
            $type->statut === StatutTypeRemuneration::FERME && ! $succession => 'Ce type est fermé aux nouvelles attributions.',
            default => null,
        };
        if ($refus !== null) {
            throw ValidationException::withMessages(['type_remuneration_id' => $refus]);
        }

        if ($type->date_debut_application !== null && $debut->lt($type->date_debut_application)) {
            throw ValidationException::withMessages([
                'date_debut' => 'Ce type ne s\'applique qu\'à partir du '.$type->date_debut_application->format('d/m/Y').'.',
            ]);
        }
        if ($type->date_fin_application !== null) {
            if ($debut->gt($type->date_fin_application)) {
                throw ValidationException::withMessages([
                    'date_debut' => 'Ce type ne s\'applique plus depuis le '.$type->date_fin_application->format('d/m/Y').'.',
                ]);
            }
            if ($fin === null || $fin->gt($type->date_fin_application)) {
                throw ValidationException::withMessages([
                    'date_fin' => 'Ce type ne s\'applique que jusqu\'au '.$type->date_fin_application->format('d/m/Y').' : indiquez une fin d\'effet au plus tard à cette date.',
                ]);
            }
        }
    }

    /**
     * Montant ou taux exigé selon le type. Un élément « saisi chaque mois » n'a pas de montant
     * permanent : il vient des saisies mensuelles. La valeur par défaut du type n'est jamais
     * reprise d'office : le montant attribué est toujours celui saisi.
     *
     * @param  array<string, mixed>  $data
     * @return array{montant: mixed, taux: mixed}
     */
    private function valeurs(TypeRemuneration $type, array $data): array
    {
        $montant = $data['montant'] ?? null;
        $taux = $data['taux'] ?? null;

        if ($type->nature_montant === NatureMontantRemuneration::VARIABLE) {
            if (filled($montant) || filled($taux)) {
                throw ValidationException::withMessages([
                    'montant' => 'Ce type est saisi chaque mois : aucun montant permanent n\'est attribué, le montant se saisit mois par mois.',
                ]);
            }

            return ['montant' => null, 'taux' => null];
        }

        if ($type->mode_calcul === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE) {
            if (! filled($taux) || (float) $taux <= 0 || (float) $taux > 100) {
                throw ValidationException::withMessages(['taux' => 'Le taux (en % du salaire de base) est obligatoire, entre 0 et 100.']);
            }

            return ['montant' => null, 'taux' => $taux];
        }

        if (! filled($montant) || (float) $montant <= 0) {
            throw ValidationException::withMessages(['montant' => 'Le montant attribué est obligatoire et doit être supérieur à 0.']);
        }

        return ['montant' => $montant, 'taux' => null];
    }

    /** Dates dans la période d'emploi du salarié, et jamais dans un mois figé. */
    private function assertPeriodeValide(Employe $employe, Carbon $debut, ?Carbon $fin): void
    {
        $this->calendrier->exigerModifiable($employe->organization_id, $debut, 'date_debut', 'une affectation prend effet');

        if ($employe->statut === StatutEmploye::SORTI && $employe->date_sortie === null) {
            throw ValidationException::withMessages(['employe_id' => 'Ce salarié est sorti : aucune nouvelle affectation.']);
        }
        if ($employe->date_embauche !== null && $debut->lt($employe->date_embauche)) {
            throw ValidationException::withMessages([
                'date_debut' => 'Le salarié n\'est embauché qu\'à partir du '.$employe->date_embauche->format('d/m/Y').'.',
            ]);
        }
        if ($employe->date_sortie !== null) {
            if ($debut->gt($employe->date_sortie)) {
                throw ValidationException::withMessages([
                    'date_debut' => 'Le salarié est sorti le '.$employe->date_sortie->format('d/m/Y').'.',
                ]);
            }
            if ($fin !== null && $fin->gt($employe->date_sortie)) {
                throw ValidationException::withMessages([
                    'date_fin' => 'La fin d\'effet ne peut pas dépasser la date de sortie du salarié ('.$employe->date_sortie->format('d/m/Y').').',
                ]);
            }
        }
    }

    /**
     * Même salarié, même type, périodes qui se chevauchent : refusé, sauf si le type autorise le
     * cumul. Propositions « à valider » comprises. Appelé sous verrou du salarié.
     *
     * @param  list<string>  $ignorer
     */
    private function assertSansChevauchement(Employe $employe, TypeRemuneration $type, Carbon $debut, ?Carbon $fin, array $ignorer): void
    {
        if ($type->cumul_autorise) {
            return;
        }

        $conflit = AffectationRemuneration::where('employe_id', $employe->id)
            ->where('type_remuneration_id', $type->id)
            ->whereIn('statut', StatutAffectationRemuneration::occupantLaPeriode())
            ->when($ignorer !== [], fn ($q) => $q->whereKeyNot($ignorer))
            ->enVigueurEntre($debut, $fin ?? Carbon::create(9999, 12, 31))
            ->orderBy('date_debut')
            // Lecture verrouillante : elle voit la dernière version validée en base, même si la
            // transaction a déjà son instantané (MySQL REPEATABLE READ).
            ->lockForUpdate()
            ->first();

        if ($conflit !== null) {
            $periode = 'du '.$conflit->date_debut->format('d/m/Y').($conflit->date_fin ? ' au '.$conflit->date_fin->format('d/m/Y') : ', sans fin');
            $etat = $conflit->statut === StatutAffectationRemuneration::A_VALIDER ? ' (proposition à valider)' : '';
            throw ValidationException::withMessages([
                'date_debut' => "Ce salarié a déjà « {$type->libelle} » sur cette période ({$periode}){$etat}. Le cumul n'est pas autorisé pour ce type : changez son montant ou mettez-y fin.",
            ]);
        }
    }

    /** Un changement de montant prend effet après le début de l'affectation, avant sa fin, hors mois figé. */
    private function assertDateEffetChangement(AffectationRemuneration $precedente, Carbon $effet): void
    {
        if ($precedente->statut !== StatutAffectationRemuneration::VALIDEE) {
            throw ValidationException::withMessages(['affectation' => 'L\'affectation remplacée n\'est plus validée.']);
        }
        if ($effet->lte($precedente->date_debut)) {
            throw ValidationException::withMessages([
                'date_effet' => 'Le changement doit prendre effet après le début de l\'affectation ('.$precedente->date_debut->format('d/m/Y').'). Pour une affectation qui n\'a pas commencé, annulez-la et attribuez le bon montant.',
            ]);
        }
        if ($precedente->date_fin !== null && $effet->gt($precedente->date_fin)) {
            throw ValidationException::withMessages([
                'date_effet' => 'L\'affectation prend fin le '.$precedente->date_fin->format('d/m/Y').' : le changement doit prendre effet au plus tard ce jour-là.',
            ]);
        }
        $this->calendrier->exigerModifiable($precedente->organization_id, $effet, 'date_effet', 'un changement de montant prend effet');
    }

    private function assertValideeSansDemandeEnCours(AffectationRemuneration $affectation): void
    {
        if ($affectation->statut !== StatutAffectationRemuneration::VALIDEE) {
            throw ValidationException::withMessages(['affectation' => 'Seule une affectation validée peut être modifiée de cette façon.']);
        }
        if ($affectation->date_fin_demandee !== null) {
            throw ValidationException::withMessages(['affectation' => 'Une fin d\'effet attend sa validation : validez-la ou retirez-la d\'abord.']);
        }
        $successeur = $affectation->successeur()->first();
        if ($successeur !== null && $successeur->statut === StatutAffectationRemuneration::A_VALIDER) {
            throw ValidationException::withMessages(['affectation' => 'Un changement de montant attend sa validation : validez-le ou annulez-le d\'abord.']);
        }
        if ($successeur !== null) {
            throw ValidationException::withMessages(['affectation' => 'Un nouveau montant lui a déjà succédé : agissez sur l\'affectation la plus récente.']);
        }
    }

    private function verrouillerEmploye(string $employeId, string $organizationId): Employe
    {
        $employe = Employe::where('organization_id', $organizationId)->whereKey($employeId)->lockForUpdate()->first();
        if ($employe === null) {
            throw ValidationException::withMessages(['employe_id' => 'Salarié inconnu.']);
        }

        return $employe;
    }

    private function verrouiller(AffectationRemuneration $affectation): AffectationRemuneration
    {
        return AffectationRemuneration::whereKey($affectation->id)->lockForUpdate()->firstOrFail();
    }

    private function organisationDe(User $acteur): string
    {
        if (! $acteur->organization_id) {
            throw new AuthorizationException;
        }

        return $acteur->organization_id;
    }

    private function assertMemeOrganisation(AffectationRemuneration $affectation, User $acteur): void
    {
        if ($affectation->organization_id !== $this->organisationDe($acteur)) {
            throw new AuthorizationException;
        }
    }

    private function exigerDroitValidation(User $acteur, ?string $message = null): void
    {
        if (! $acteur->can(self::PERMISSION_VALIDATION)) {
            throw new AuthorizationException($message ?? 'Seule une personne habilitée à valider les primes des salariés peut effectuer cette action.');
        }
    }

    private function texte(mixed $valeur): ?string
    {
        return filled($valeur) ? trim((string) $valeur) : null;
    }

    /** @return array<string, mixed> */
    private function instantane(AffectationRemuneration $affectation): array
    {
        $valeurs = [];
        foreach (self::CHAMPS_JOURNAL as $champ) {
            $valeur = $affectation->{$champ};
            $valeurs[$champ] = match (true) {
                $valeur instanceof \BackedEnum => $valeur->value,
                $valeur instanceof \DateTimeInterface => $valeur->format('Y-m-d'),
                default => $valeur,
            };
        }

        return $valeurs;
    }

    /**
     * @param  array<string, mixed>|null  $avant
     * @param  array<string, mixed>|null  $apres
     */
    private function journaliser(AffectationRemuneration $affectation, AuditEvent $evenement, User $acteur, ?array $avant, ?array $apres, string $description): void
    {
        $affectation->loadMissing(['employe.personne', 'type']);

        $this->audit->record($affectation, $evenement, $acteur, $avant, $apres, [
            'module' => self::MODULE_AUDIT,
            'description' => $description,
            'salarie' => $affectation->employe?->nom_complet,
            'code' => $affectation->type?->code,
        ]);
    }
}
