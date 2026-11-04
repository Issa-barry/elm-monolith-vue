<?php

namespace App\Support\Rh;

use App\Enums\ModeCalculRemuneration;
use App\Enums\NatureMontantRemuneration;
use App\Enums\StatutEmploye;
use App\Enums\StatutTypeRemuneration;
use App\Models\AffectationRemuneration;
use App\Models\Employe;
use App\Models\SaisieRemuneration;
use App\Models\TransmissionPaie;
use App\Models\TransmissionPaieLigne;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\Rh\AffectationRemunerationService;
use App\Services\Rh\Paie\CalendrierPaieService;
use Illuminate\Support\Collection;

/**
 * Mise en forme des primes de la fiche salarié (ADR 0052). Les droits d'agir (agence du salarié)
 * sont calculés ici une fois pour toute la liste ; le serveur les revérifie à chaque action.
 */
class AffectationRemunerationPresenter
{
    /** @var list<string> */
    private array $sitesEcriture;

    private bool $toutesAgences;

    /** @var array<string, true> */
    private array $transmises = [];

    public function __construct(private readonly User $user)
    {
        $this->toutesAgences = $user->agitSurToutesLesAgences();
        $this->sitesEcriture = $this->toutesAgences ? [] : $user->sites()->pluck('sites.id')->all();
    }

    /**
     * @param  Collection<int, AffectationRemuneration>  $affectations
     * @return list<array<string, mixed>>
     */
    public function liste(Collection $affectations): array
    {
        $this->transmises = TransmissionPaieLigne::whereIn('affectation_id', $affectations->pluck('id'))
            ->whereHas('transmission', fn ($q) => $q->whereIn('statut', TransmissionPaie::STATUTS_FIGES))
            ->pluck('affectation_id')
            ->flip()
            ->map(fn () => true)
            ->all();

        return $affectations->map(fn (AffectationRemuneration $a) => $this->ligne($a))->values()->all();
    }

    /**
     * Données des fenêtres de la fiche : types, primes déjà occupées (aide à la saisie, le refus
     * serveur d'AFF-004 reste l'autorité), premier jour modifiable, droit d'attribuer.
     *
     * @return array<string, mixed>
     */
    public function optionsFiche(Employe $employe): array
    {
        $orgId = $employe->organization_id;
        $premierJour = app(CalendrierPaieService::class)->premierJourModifiable($orgId);
        $occupations = app(AffectationRemunerationService::class)->primesOccupeesParEmploye($orgId, [$employe->id]);

        return [
            'types' => $this->types($orgId),
            'primes_occupees' => $occupations[$employe->id] ?? [],
            'premier_jour_modifiable' => $premierJour?->toDateString(),
            'peut_attribuer' => PerimetreEmploye::peutModifier($this->user, $employe)
                && ! ($employe->statut === StatutEmploye::SORTI && $employe->date_sortie === null),
        ];
    }

    /**
     * Tous les types de l'organisation ; `attribuable` = actif (la période d'application est
     * revérifiée par le serveur à la date d'effet).
     *
     * @return list<array<string, mixed>>
     */
    private function types(string $orgId): array
    {
        return TypeRemuneration::where('organization_id', $orgId)
            ->orderBy('libelle')
            ->get()
            ->map(fn (TypeRemuneration $t) => [
                'value' => $t->id,
                'label' => $t->libelle,
                'valorise' => $t->categorie->estValorise(),
                'mode_calcul' => $t->mode_calcul->value,
                'periodicite' => $t->periodicite->value,
                'nature_montant' => $t->nature_montant->value,
                'cumul_autorise' => $t->cumul_autorise,
                'montant_defaut' => $t->montant_defaut !== null ? (float) $t->montant_defaut : null,
                'taux_defaut' => $t->taux_defaut !== null ? (float) $t->taux_defaut : null,
                'date_fin_application' => $t->date_fin_application?->toDateString(),
                'attribuable' => $t->statut === StatutTypeRemuneration::ACTIF,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function ligne(AffectationRemuneration $a): array
    {
        $type = $a->type;
        $employe = $a->employe;
        $etat = $a->etat();
        $successeur = $a->relationLoaded('successeur') ? $a->successeur : $a->successeur()->first();

        return [
            'id' => $a->id,
            'employe' => [
                'id' => $employe?->id,
                'nom_complet' => $employe?->nom_complet,
                'matricule' => $employe?->matricule,
                'site' => $employe?->site?->nom,
            ],
            'type' => [
                'id' => $type->id,
                'code' => $type->code,
                'libelle' => $type->libelle,
                'categorie' => $type->categorie->value,
                'categorie_label' => $type->categorie->label(),
                'valorise' => $type->categorie->estValorise(),
                'mode_calcul' => $type->mode_calcul->value,
                'periodicite' => $type->periodicite->value,
                'periodicite_label' => $type->periodicite->label(),
                'nature_montant' => $type->nature_montant->value,
                // Proposés dans « Montants mensuels » d'un montant variable (préremplissage seulement).
                'montant_defaut' => $type->montant_defaut !== null ? (float) $type->montant_defaut : null,
                'taux_defaut' => $type->taux_defaut !== null ? (float) $type->taux_defaut : null,
                'proratisable' => $type->proratisable,
                'statut' => $type->statut->value,
            ],
            'date_debut' => $a->date_debut->toDateString(),
            'date_fin' => $a->date_fin?->toDateString(),
            'montant' => $a->montant !== null ? (float) $a->montant : null,
            'taux' => $a->taux !== null ? (float) $a->taux : null,
            'valeur_label' => $this->valeurLabel($a),
            'commentaire' => $a->commentaire,
            'statut' => $a->statut->value,
            'etat' => $etat['value'],
            'etat_label' => $etat['label'],
            'attend_validation' => $a->attendValidation(),
            'remplace_affectation_id' => $a->remplace_affectation_id,
            'est_changement' => $a->remplace_affectation_id !== null,
            'successeur_statut' => $successeur?->statut->value,
            'demande_fin' => $a->date_fin_demandee ? [
                'date' => $a->date_fin_demandee->toDateString(),
                'motif' => $a->motif_fin,
                'par' => $a->demandeurFin?->name,
            ] : null,
            'motif_fin' => $a->motif_fin,
            'motif_annulation' => $a->motif_annulation,
            'est_transmise' => isset($this->transmises[$a->id]),
            'peut_agir' => $this->toutesAgences || ($employe?->site_id !== null && in_array($employe->site_id, $this->sitesEcriture, true)),
            'saisies' => $type->nature_montant === NatureMontantRemuneration::VARIABLE
                ? $a->saisies->map(fn (SaisieRemuneration $s) => [
                    'id' => $s->id,
                    'annee' => $s->annee,
                    'mois' => $s->mois,
                    'montant' => $s->montant !== null ? (float) $s->montant : null,
                    'taux' => $s->taux !== null ? (float) $s->taux : null,
                    'commentaire' => $s->commentaire,
                    'statut' => $s->statut->value,
                    'statut_label' => $s->statut->label(),
                ])->values()->all()
                : [],
            'cree_par' => $a->auteur?->name,
            'valide_par' => $a->valideur?->name,
            'valide_le' => $a->valide_le?->format('d/m/Y H:i'),
            'created_at' => $a->created_at?->format('d/m/Y H:i'),
            'updated_at' => $a->updated_at?->format('d/m/Y H:i'),
        ];
    }

    private function valeurLabel(AffectationRemuneration $a): string
    {
        $type = $a->type;
        if ($type->nature_montant === NatureMontantRemuneration::VARIABLE) {
            return 'Saisi chaque mois';
        }
        if ($type->mode_calcul === ModeCalculRemuneration::POURCENTAGE_SALAIRE_BASE) {
            return rtrim(rtrim(number_format((float) $a->taux, 2, ',', ' '), '0'), ',').' % du salaire de base';
        }

        return number_format((float) $a->montant, 0, ',', ' ').' GNF';
    }

    /** Relations à charger pour liste(). */
    public const RELATIONS = [
        'type', 'employe.personne', 'employe.site:id,nom', 'auteur.personne', 'valideur.personne',
        'demandeurFin.personne', 'saisies', 'successeur',
    ];
}
