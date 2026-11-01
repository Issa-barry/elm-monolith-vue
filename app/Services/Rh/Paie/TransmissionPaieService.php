<?php

namespace App\Services\Rh\Paie;

use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaie;
use App\Enums\StatutTransmissionPaie;
use App\Models\Organization;
use App\Models\PaiePeriode;
use App\Models\TransmissionPaie;
use App\Models\TransmissionPaieLigne;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Seul point d'écriture des transmissions mensuelles RH → Comptabilité (docs/transmission-paie.md,
 * ADR 0040).
 *
 * Brouillon (montants calculés, recalculés automatiquement à l'ouverture et avant la validation)
 * → validée par les RH : figée (empreinte) ET aussitôt disponible pour la Comptabilité — statut
 * « transmise », sans action supplémentaire (ADR 0047, révise l'ADR 0040) → remplacée par une
 * version suivante. Une correction ouvre toujours une nouvelle version, avec un motif ; tant que la
 * Comptabilité n'a pas repris la version, sa validation peut être annulée (version conservée). Les
 * versions précédentes restent consultables. Valider ne déclenche ni paiement ni écriture.
 *
 * Les droits (`rh-transmissions-paie.preparer`, `.valider`, `.transmettre`) et l'organisation sont
 * contrôlés ICI. Une transmission couvre tous les salariés de l'organisation : préparer, valider et
 * transmettre exigent d'agir sur toutes les agences. Les opérations d'un même mois sont
 * sérialisées par un verrou sur l'organisation.
 */
class TransmissionPaieService
{
    public const PERMISSION_PREPARER = 'rh-transmissions-paie.preparer';

    public const PERMISSION_VALIDER = 'rh-transmissions-paie.valider';

    public const PERMISSION_TRANSMETTRE = 'rh-transmissions-paie.transmettre';

    private const MODULE_AUDIT = 'rh_transmissions_paie';

    public function __construct(
        private readonly CalculElementsPaieService $calcul,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Prépare un brouillon pour le mois. Une version figée existe déjà : c'est une nouvelle version
     * (correction), motif obligatoire.
     */
    public function preparer(User $acteur, int $annee, int $mois, ?string $motif = null): TransmissionPaie
    {
        $organizationId = $this->organisationDe($acteur);
        $this->exigerDroit($acteur, self::PERMISSION_PREPARER);
        $this->exigerPerimetreComplet($acteur);

        try {
            return DB::transaction(function () use ($acteur, $organizationId, $annee, $mois, $motif) {
                $this->verrouillerOrganisation($organizationId);
                $this->assertComptabiliteNAPasValide($organizationId, $annee, $mois);

                $versions = TransmissionPaie::where('organization_id', $organizationId)
                    ->where('annee', $annee)->where('mois', $mois)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->get();

                $brouillon = $versions->firstWhere('statut', StatutTransmissionPaie::BROUILLON);
                if ($brouillon !== null) {
                    throw ValidationException::withMessages([
                        'mois' => "Un brouillon existe déjà pour ce mois (version {$brouillon->version}) : recalculez-le ou abandonnez-le.",
                    ]);
                }

                $precedente = $versions->first(fn (TransmissionPaie $t) => $t->statut->estFigee());
                if ($precedente !== null && blank($motif)) {
                    throw ValidationException::withMessages([
                        'motif' => "La version {$precedente->version} est déjà figée : indiquez le motif de la nouvelle version.",
                    ]);
                }

                $resultat = $this->calcul->calculer($organizationId, $annee, $mois);

                $transmission = TransmissionPaie::create([
                    'organization_id' => $organizationId,
                    'annee' => $annee,
                    'mois' => $mois,
                    'version' => ((int) $versions->max('version')) + 1,
                    'statut' => StatutTransmissionPaie::BROUILLON->value,
                    'motif' => filled($motif) ? trim($motif) : null,
                    'version_precedente_id' => $precedente?->id,
                    'preparee_par' => $acteur->id,
                    'preparee_le' => now(),
                    ...$this->synthese($resultat),
                ]);
                $this->enregistrerLignes($transmission, $resultat['lignes']);

                $this->journaliser($transmission, AuditEvent::CREATED, $acteur, null, $this->instantane($transmission),
                    $precedente === null
                        ? "Transmission de {$transmission->libelleMois()} préparée (version {$transmission->version})"
                        : "Version {$transmission->version} de {$transmission->libelleMois()} ouverte : ".trim((string) $motif));

                return $transmission;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['mois' => 'Une version de ce mois vient d\'être préparée. Rechargez la page.']);
        }
    }

    /** Recalcule un brouillon à partir des données RH actuelles. */
    public function recalculer(TransmissionPaie $transmission, User $acteur): TransmissionPaie
    {
        $this->assertMemeOrganisation($transmission, $acteur);
        $this->exigerDroit($acteur, self::PERMISSION_PREPARER);
        $this->exigerPerimetreComplet($acteur);

        return DB::transaction(function () use ($transmission, $acteur) {
            $this->verrouillerOrganisation($transmission->organization_id);
            $transmission = $this->verrouiller($transmission);
            $this->exigerStatut($transmission, StatutTransmissionPaie::BROUILLON, 'Seul un brouillon se recalcule : une version figée ne change plus.');
            $this->assertComptabiliteNAPasValide($transmission->organization_id, $transmission->annee, $transmission->mois);

            $resultat = $this->calcul->calculer($transmission->organization_id, $transmission->annee, $transmission->mois);
            $this->persisterCalcul($transmission, $resultat, $acteur, "Brouillon de {$transmission->libelleMois()} recalculé");

            return $transmission;
        });
    }

    /**
     * Recalcul automatique d'un brouillon (ouverture de l'écran, avant la validation) : seulement si
     * les données RH ont changé depuis le dernier calcul, et si l'acteur peut préparer la paie.
     * Retourne vrai si les montants ont été mis à jour. Jamais sur une version validée.
     */
    public function actualiserBrouillon(TransmissionPaie $transmission, User $acteur): bool
    {
        if ($transmission->statut !== StatutTransmissionPaie::BROUILLON
            || $transmission->organization_id !== $acteur->organization_id
            || ! $acteur->can(self::PERMISSION_PREPARER) || ! $acteur->agitSurToutesLesAgences()
            || $this->comptabiliteAValide($transmission->organization_id, $transmission->annee, $transmission->mois)) {
            return false;
        }

        return DB::transaction(function () use ($transmission, $acteur) {
            $this->verrouillerOrganisation($transmission->organization_id);
            $transmission = $this->verrouiller($transmission);
            if ($transmission->statut !== StatutTransmissionPaie::BROUILLON) {
                return false;
            }

            $resultat = $this->calcul->calculer($transmission->organization_id, $transmission->annee, $transmission->mois);
            if ($resultat['empreinte'] === $transmission->empreinte) {
                return false;
            }

            $this->persisterCalcul($transmission, $resultat, $acteur, "Brouillon de {$transmission->libelleMois()} recalculé automatiquement (données RH modifiées)");

            return true;
        });
    }

    /** Le brouillon reflète-t-il les données RH du moment ? Lecture seule (Comptabilité, lecteurs). */
    public function estAJour(TransmissionPaie $transmission): bool
    {
        return $this->calcul->calculer($transmission->organization_id, $transmission->annee, $transmission->mois)['empreinte']
            === $transmission->empreinte;
    }

    /** @param  array<string, mixed>  $resultat */
    private function persisterCalcul(TransmissionPaie $transmission, array $resultat, User $acteur, string $description): void
    {
        $avant = $this->instantane($transmission);
        TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)->delete();
        $this->enregistrerLignes($transmission, $resultat['lignes']);
        $transmission->update([...$this->synthese($resultat), 'preparee_par' => $acteur->id, 'preparee_le' => now()]);

        [$ancien, $nouveau] = $this->audit->diffFields($avant, $this->instantane($transmission), ['nb_salaries', 'total_verse', 'total_valorise', 'nb_anomalies_bloquantes']);
        $this->journaliser($transmission, AuditEvent::AUTO_RECALCULATED, $acteur, $ancien, $nouveau,
            $description.($nouveau === null ? ' (aucun changement)' : ''));
    }

    public function abandonner(TransmissionPaie $transmission, User $acteur): TransmissionPaie
    {
        $this->assertMemeOrganisation($transmission, $acteur);
        $this->exigerDroit($acteur, self::PERMISSION_PREPARER);
        $this->exigerPerimetreComplet($acteur);

        return DB::transaction(function () use ($transmission, $acteur) {
            $this->verrouillerOrganisation($transmission->organization_id);
            $transmission = $this->verrouiller($transmission);
            $this->exigerStatut($transmission, StatutTransmissionPaie::BROUILLON, 'Seul un brouillon peut être abandonné.');

            TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)->delete();
            $transmission->update([
                'statut' => StatutTransmissionPaie::ABANDONNEE->value,
                'abandonnee_par' => $acteur->id,
                'abandonnee_le' => now(),
            ]);
            $this->journaliser($transmission, AuditEvent::CANCELLED, $acteur, ['statut' => StatutTransmissionPaie::BROUILLON->value],
                ['statut' => StatutTransmissionPaie::ABANDONNEE->value], "Brouillon de {$transmission->libelleMois()} abandonné");

            return $transmission;
        });
    }

    /**
     * Valide un brouillon : il est figé ET mis à disposition de la Comptabilité (ADR 0047). Un
     * dernier recalcul a lieu d'abord ; si les montants ont changé, la validation est refusée pour
     * que la version validée soit exactement celle consultée. Refusé aussi s'il reste une anomalie
     * bloquante. Ne déclenche ni paiement ni écriture comptable.
     */
    public function valider(TransmissionPaie $transmission, User $acteur): TransmissionPaie
    {
        $this->assertMemeOrganisation($transmission, $acteur);
        $this->exigerDroit($acteur, self::PERMISSION_VALIDER);
        $this->exigerPerimetreComplet($acteur);

        if ($this->actualiserBrouillon($transmission, $acteur)) {
            throw ValidationException::withMessages([
                'transmission' => 'Les données RH avaient changé : le brouillon vient d\'être recalculé. Vérifiez les nouveaux montants, puis validez.',
            ]);
        }

        return DB::transaction(function () use ($transmission, $acteur) {
            $this->verrouillerOrganisation($transmission->organization_id);
            $transmission = $this->verrouiller($transmission);
            $this->exigerStatut($transmission, StatutTransmissionPaie::BROUILLON, 'Seul un brouillon peut être validé.');
            $this->assertComptabiliteNAPasValide($transmission->organization_id, $transmission->annee, $transmission->mois);

            $actuel = $this->calcul->calculer($transmission->organization_id, $transmission->annee, $transmission->mois);
            $bloquantes = array_filter($actuel['anomalies'], fn (array $a) => $a['niveau'] === 'bloquante');
            if ($bloquantes !== []) {
                throw ValidationException::withMessages([
                    'transmission' => count($bloquantes).' anomalie(s) bloquante(s) à corriger avant de valider (puis recalculez).',
                ]);
            }
            $stockees = TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)->get();
            if ($actuel['empreinte'] !== CalculElementsPaieService::empreinte($stockees) || $actuel['empreinte'] !== $transmission->empreinte) {
                throw ValidationException::withMessages([
                    'transmission' => 'Les données RH ont changé depuis le dernier calcul : recalculez le brouillon, vérifiez-le, puis validez.',
                ]);
            }

            // La version disponible jusqu'ici pour la Comptabilité (et une ancienne version validée
            // jamais mise à disposition) est remplacée dès maintenant.
            TransmissionPaie::where('organization_id', $transmission->organization_id)
                ->where('annee', $transmission->annee)->where('mois', $transmission->mois)
                ->whereIn('statut', [StatutTransmissionPaie::VALIDEE->value, StatutTransmissionPaie::TRANSMISE->value])
                ->whereKeyNot($transmission->id)
                ->get()
                ->each(fn (TransmissionPaie $ancienne) => $this->remplacer($ancienne, $transmission, $acteur));

            $transmission->update([
                'statut' => StatutTransmissionPaie::TRANSMISE->value,
                'validee_par' => $acteur->id,
                'validee_le' => now(),
                'transmise_par' => $acteur->id,
                'transmise_le' => now(),
            ]);
            $this->journaliser($transmission, AuditEvent::VALIDATED, $acteur, ['statut' => StatutTransmissionPaie::BROUILLON->value],
                ['statut' => StatutTransmissionPaie::TRANSMISE->value, 'empreinte' => $transmission->empreinte],
                "Version {$transmission->version} de {$transmission->libelleMois()} validée et figée : disponible pour la Comptabilité");

            return $transmission;
        });
    }

    /**
     * Met à disposition de la Comptabilité une version validée AVANT l'ADR 0047 (statut « validee ») ;
     * depuis, la validation le fait d'elle-même. La version disponible auparavant pour ce mois est
     * remplacée. Une version n'est transmise qu'une fois, et seulement si son contenu figé est intact.
     */
    public function transmettre(TransmissionPaie $transmission, User $acteur): TransmissionPaie
    {
        $this->assertMemeOrganisation($transmission, $acteur);
        $this->exigerDroit($acteur, self::PERMISSION_TRANSMETTRE);
        $this->exigerPerimetreComplet($acteur);

        return DB::transaction(function () use ($transmission, $acteur) {
            $this->verrouillerOrganisation($transmission->organization_id);
            $transmission = $this->verrouiller($transmission);
            if ($transmission->statut === StatutTransmissionPaie::TRANSMISE) {
                throw ValidationException::withMessages(['transmission' => 'Cette version a déjà été transmise.']);
            }
            $this->exigerStatut($transmission, StatutTransmissionPaie::VALIDEE, 'Seule une version validée peut être transmise.');
            $this->assertComptabiliteNAPasValide($transmission->organization_id, $transmission->annee, $transmission->mois);
            if (! $this->estIntegre($transmission)) {
                throw ValidationException::withMessages(['transmission' => 'Le contenu de cette version ne correspond plus à son empreinte : transmission refusée.']);
            }

            TransmissionPaie::where('organization_id', $transmission->organization_id)
                ->where('annee', $transmission->annee)->where('mois', $transmission->mois)
                ->where('statut', StatutTransmissionPaie::TRANSMISE->value)
                ->get()
                ->each(fn (TransmissionPaie $ancienne) => $this->remplacer($ancienne, $transmission, $acteur));

            $transmission->update([
                'statut' => StatutTransmissionPaie::TRANSMISE->value,
                'transmise_par' => $acteur->id,
                'transmise_le' => now(),
            ]);
            $this->journaliser($transmission, AuditEvent::TRANSMITTED, $acteur, ['statut' => StatutTransmissionPaie::VALIDEE->value],
                ['statut' => StatutTransmissionPaie::TRANSMISE->value],
                "Version {$transmission->version} de {$transmission->libelleMois()} transmise à la Comptabilité");

            return $transmission;
        });
    }

    /**
     * Annule la validation RH d'une version que la Comptabilité n'a pas encore reprise (ADR 0047).
     * La version est conservée telle quelle (statut « validation annulée », elle ne fige plus le
     * mois) ; la version qu'elle avait remplacée redevient celle de la Comptabilité ; un nouveau
     * brouillon recalculé est ouvert, avec le motif, et devra être validé à nouveau. Retourne ce
     * brouillon.
     */
    public function annulerValidation(TransmissionPaie $transmission, User $acteur, string $motif): TransmissionPaie
    {
        $this->assertMemeOrganisation($transmission, $acteur);
        $this->exigerDroit($acteur, self::PERMISSION_VALIDER);
        $this->exigerPerimetreComplet($acteur);
        $motif = trim($motif);
        if ($motif === '') {
            throw ValidationException::withMessages(['motif' => 'Indiquez le motif de l\'annulation.']);
        }

        return DB::transaction(function () use ($transmission, $acteur, $motif) {
            $this->verrouillerOrganisation($transmission->organization_id);
            $transmission = $this->verrouiller($transmission);
            $this->assertAnnulable($transmission);

            $avant = $transmission->statut->value;
            $transmission->update(['statut' => StatutTransmissionPaie::VALIDATION_ANNULEE->value]);
            $this->journaliser($transmission, AuditEvent::CANCELLED, $acteur, ['statut' => $avant],
                ['statut' => StatutTransmissionPaie::VALIDATION_ANNULEE->value, 'motif' => $motif],
                "Validation de la version {$transmission->version} de {$transmission->libelleMois()} annulée : {$motif}");

            // La version que celle-ci avait remplacée redevient la version de la Comptabilité.
            $precedente = $transmission->version_precedente_id !== null
                ? TransmissionPaie::whereKey($transmission->version_precedente_id)->lockForUpdate()->first()
                : null;
            if ($precedente !== null && $precedente->statut === StatutTransmissionPaie::REMPLACEE) {
                $precedente->update(['statut' => StatutTransmissionPaie::TRANSMISE->value]);
                $this->journaliser($precedente, AuditEvent::STATUS_CHANGED, $acteur, ['statut' => StatutTransmissionPaie::REMPLACEE->value],
                    ['statut' => StatutTransmissionPaie::TRANSMISE->value],
                    "Version {$precedente->version} de nouveau disponible pour la Comptabilité (validation de la version {$transmission->version} annulée)");
            }

            $brouillon = TransmissionPaie::where('organization_id', $transmission->organization_id)
                ->where('annee', $transmission->annee)->where('mois', $transmission->mois)
                ->where('statut', StatutTransmissionPaie::BROUILLON->value)
                ->first();

            return $brouillon ?? $this->ouvrirBrouillon($transmission, $acteur, "Validation de la version {$transmission->version} annulée : {$motif}");
        });
    }

    /** L'annulation serait-elle acceptée (affichage du bouton) ? */
    public function peutAnnulerValidation(TransmissionPaie $transmission, User $acteur): bool
    {
        if ($transmission->organization_id !== $acteur->organization_id
            || ! $acteur->can(self::PERMISSION_VALIDER) || ! $acteur->agitSurToutesLesAgences()) {
            return false;
        }

        try {
            $this->assertAnnulable($transmission);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function assertAnnulable(TransmissionPaie $transmission): void
    {
        if (! in_array($transmission->statut, [StatutTransmissionPaie::TRANSMISE, StatutTransmissionPaie::VALIDEE], true)) {
            throw ValidationException::withMessages(['transmission' => 'Seule la version validée en vigueur peut voir sa validation annulée.']);
        }
        if ($transmission->prise_en_charge_reference !== null) {
            throw ValidationException::withMessages([
                'transmission' => 'La Comptabilité a déjà repris cette version : la correction passe par une nouvelle version ou par le mois suivant.',
            ]);
        }
        if ($this->comptabiliteAValide($transmission->organization_id, $transmission->annee, $transmission->mois)) {
            throw ValidationException::withMessages([
                'transmission' => 'La Comptabilité a déjà validé, payé ou clôturé la paie de ce mois : la correction se fait sur le mois suivant.',
            ]);
        }
    }

    /** Nouveau brouillon recalculé, numéroté à la suite, rattaché à la dernière version figée. */
    private function ouvrirBrouillon(TransmissionPaie $origine, User $acteur, string $motif): TransmissionPaie
    {
        $versions = TransmissionPaie::where('organization_id', $origine->organization_id)
            ->where('annee', $origine->annee)->where('mois', $origine->mois)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->get();
        $precedente = $versions->first(fn (TransmissionPaie $t) => $t->statut->estFigee());
        $resultat = $this->calcul->calculer($origine->organization_id, $origine->annee, $origine->mois);

        $brouillon = TransmissionPaie::create([
            'organization_id' => $origine->organization_id,
            'annee' => $origine->annee,
            'mois' => $origine->mois,
            'version' => ((int) $versions->max('version')) + 1,
            'statut' => StatutTransmissionPaie::BROUILLON->value,
            'motif' => $motif,
            'version_precedente_id' => $precedente?->id,
            'preparee_par' => $acteur->id,
            'preparee_le' => now(),
            ...$this->synthese($resultat),
        ]);
        $this->enregistrerLignes($brouillon, $resultat['lignes']);
        $this->journaliser($brouillon, AuditEvent::CREATED, $acteur, null, $this->instantane($brouillon),
            "Version {$brouillon->version} de {$brouillon->libelleMois()} ouverte : {$motif}");

        return $brouillon;
    }

    /** Le contenu figé correspond-il toujours à l'empreinte enregistrée à la validation ? */
    public function estIntegre(TransmissionPaie $transmission): bool
    {
        $lignes = TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)->get();

        return $transmission->empreinte !== null && hash_equals($transmission->empreinte, CalculElementsPaieService::empreinte($lignes));
    }

    /**
     * Écarts par salarié et par élément entre deux jeux de lignes (montant du mois, toutes parties
     * du mois confondues).
     *
     * @param  iterable<array<string, mixed>|TransmissionPaieLigne>  $avant
     * @param  iterable<array<string, mixed>|TransmissionPaieLigne>  $apres
     * @return list<array{employe_id: string, salarie: string, site_id: ?string, element: string, avant: ?float, apres: ?float, ecart: float}>
     */
    public function differences(iterable $avant, iterable $apres): array
    {
        $a = $this->totauxParElement($avant);
        $b = $this->totauxParElement($apres);
        $ecarts = [];

        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $cle) {
            $va = $a[$cle]['montant'] ?? null;
            $vb = $b[$cle]['montant'] ?? null;
            if ($va !== null && $vb !== null && abs($va - $vb) < 0.005) {
                continue;
            }
            $ref = $b[$cle] ?? $a[$cle];
            $ecarts[] = [
                'employe_id' => $ref['employe_id'],
                'salarie' => $ref['salarie'],
                'site_id' => $ref['site_id'],
                'element' => $ref['element'],
                'avant' => $va,
                'apres' => $vb,
                'ecart' => round(($vb ?? 0) - ($va ?? 0), 2),
            ];
        }

        usort($ecarts, fn ($x, $y) => [$x['salarie'], $x['element']] <=> [$y['salarie'], $y['element']]);

        return $ecarts;
    }

    /**
     * Changements intervenus dans les données RH depuis une version figée : ce qu'une nouvelle
     * version contiendrait. Lecture seule.
     *
     * @return list<array<string, mixed>>
     */
    public function changementsDepuis(TransmissionPaie $transmission): array
    {
        $actuel = $this->calcul->calculer($transmission->organization_id, $transmission->annee, $transmission->mois);

        return $this->differences(
            TransmissionPaieLigne::where('transmission_paie_id', $transmission->id)->get(),
            $actuel['lignes'],
        );
    }

    /**
     * @param  iterable<array<string, mixed>|TransmissionPaieLigne>  $lignes
     * @return array<string, array{employe_id: string, salarie: string, site_id: ?string, element: string, montant: float}>
     */
    private function totauxParElement(iterable $lignes): array
    {
        $totaux = [];
        foreach ($lignes as $ligne) {
            $l = $ligne instanceof TransmissionPaieLigne ? $ligne->toArray() : $ligne;
            $element = $l['origine'] === TransmissionPaieLigne::ORIGINE_CONTRAT ? 'contrat' : 'type:'.$l['type_remuneration_id'];
            $cle = $l['employe_id'].'|'.$element;
            $totaux[$cle] ??= [
                'employe_id' => $l['employe_id'],
                'salarie' => $l['nom_salarie'],
                'site_id' => $l['site_id'],
                'element' => $l['origine'] === TransmissionPaieLigne::ORIGINE_CONTRAT ? 'Salaire de base' : $l['libelle'],
                'montant' => 0.0,
            ];
            $totaux[$cle]['montant'] = round($totaux[$cle]['montant'] + (float) $l['montant'], 2);
        }

        return $totaux;
    }

    private function remplacer(TransmissionPaie $ancienne, TransmissionPaie $nouvelle, User $acteur): void
    {
        $avant = $ancienne->statut->value;
        $ancienne->update(['statut' => StatutTransmissionPaie::REMPLACEE->value]);
        $this->journaliser($ancienne, AuditEvent::STATUS_CHANGED, $acteur, ['statut' => $avant],
            ['statut' => StatutTransmissionPaie::REMPLACEE->value], "Version {$ancienne->version} remplacée par la version {$nouvelle->version}");
    }

    /**
     * @param  array<string, mixed>  $resultat
     * @return array<string, mixed>
     */
    private function synthese(array $resultat): array
    {
        return [
            'anomalies' => $resultat['anomalies'],
            'nb_salaries' => $resultat['nb_salaries'],
            'total_verse' => $resultat['total_verse'],
            'total_valorise' => $resultat['total_valorise'],
            'empreinte' => $resultat['empreinte'],
        ];
    }

    /** @param  list<array<string, mixed>>  $lignes */
    private function enregistrerLignes(TransmissionPaie $transmission, array $lignes): void
    {
        foreach ($lignes as $ligne) {
            TransmissionPaieLigne::create([
                ...$ligne,
                'transmission_paie_id' => $transmission->id,
                'organization_id' => $transmission->organization_id,
            ]);
        }
    }

    /**
     * La Comptabilité a validé la paie de ce mois : plus de nouvelle version, les changements
     * passeront en régularisation du mois suivant (lot 5).
     */
    /** Période de paie validée, payée ou clôturée par la Comptabilité. */
    public function comptabiliteAValide(string $organizationId, int $annee, int $mois): bool
    {
        $periode = PaiePeriode::where('organization_id', $organizationId)->where('annee', $annee)->where('mois', $mois)->first();

        return $periode !== null && $periode->statut instanceof StatutPeriodePaie && $periode->statut->estVerrouille();
    }

    private function assertComptabiliteNAPasValide(string $organizationId, int $annee, int $mois): void
    {
        if ($this->comptabiliteAValide($organizationId, $annee, $mois)) {
            throw ValidationException::withMessages([
                'mois' => 'La Comptabilité a déjà validé la paie de ce mois : il ne peut plus recevoir de nouvelle version.',
            ]);
        }
    }

    private function exigerStatut(TransmissionPaie $transmission, StatutTransmissionPaie $attendu, string $message): void
    {
        if ($transmission->statut !== $attendu) {
            throw ValidationException::withMessages(['transmission' => $message]);
        }
    }

    private function verrouillerOrganisation(string $organizationId): void
    {
        Organization::whereKey($organizationId)->lockForUpdate()->firstOrFail();
    }

    private function verrouiller(TransmissionPaie $transmission): TransmissionPaie
    {
        return TransmissionPaie::whereKey($transmission->id)->lockForUpdate()->firstOrFail();
    }

    private function exigerDroit(User $acteur, string $permission): void
    {
        if (! $acteur->can($permission)) {
            throw new AuthorizationException('Vous n\'avez pas le droit d\'effectuer cette action sur la transmission de paie.');
        }
    }

    /** Une transmission couvre tous les salariés : il faut agir sur toutes les agences. */
    private function exigerPerimetreComplet(User $acteur): void
    {
        if (! $acteur->agitSurToutesLesAgences()) {
            throw new AuthorizationException('La transmission couvre les salariés de toutes les agences : cette action est réservée aux utilisateurs qui agissent sur toutes les agences.');
        }
    }

    private function organisationDe(User $acteur): string
    {
        if (! $acteur->organization_id) {
            throw new AuthorizationException;
        }

        return $acteur->organization_id;
    }

    private function assertMemeOrganisation(TransmissionPaie $transmission, User $acteur): void
    {
        if ($transmission->organization_id !== $this->organisationDe($acteur)) {
            throw new AuthorizationException;
        }
    }

    /** @return array<string, mixed> */
    private function instantane(TransmissionPaie $transmission): array
    {
        return [
            'version' => $transmission->version,
            'statut' => $transmission->statut->value,
            'nb_salaries' => $transmission->nb_salaries,
            'total_verse' => $transmission->total_verse,
            'total_valorise' => $transmission->total_valorise,
            'nb_anomalies_bloquantes' => count(array_filter($transmission->anomaliesListe(), fn (array $a) => $a['niveau'] === 'bloquante')),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $avant
     * @param  array<string, mixed>|null  $apres
     */
    private function journaliser(TransmissionPaie $transmission, AuditEvent $evenement, User $acteur, ?array $avant, ?array $apres, string $description): void
    {
        $this->audit->record($transmission, $evenement, $acteur, $avant, $apres, [
            'module' => self::MODULE_AUDIT,
            'description' => $description,
            'periode' => sprintf('%02d/%d', $transmission->mois, $transmission->annee),
            'version' => $transmission->version,
        ]);
    }
}
