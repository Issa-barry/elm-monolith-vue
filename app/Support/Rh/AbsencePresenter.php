<?php

namespace App\Support\Rh;

use App\Enums\StatutEmploye;
use App\Models\Absence;
use App\Models\Employe;
use App\Models\MotifAbsence;
use App\Models\User;
use App\Services\SiteScopeService;
use Illuminate\Support\Collection;

/**
 * Données envoyées aux écrans Absences (liste, fiche salarié, détail) : valeurs déjà formatées et
 * actions calculées par le serveur (mêmes règles qu'AbsenceService), pour que l'écran n'affiche que ce qui sera
 * accepté.
 */
final class AbsencePresenter
{
    /** Relations à charger avant ligne(). */
    public const RELATIONS = ['employe.personne', 'employe.site:id,nom,code', 'motif:id,libelle', 'auteur:id,personne_id', 'auteur.personne:id,nom,prenom'];

    /** Agences de rattachement (null = toutes), calculées une fois pour toute la liste. */
    private ?Collection $sitesEcriture = null;

    private bool $sitesEcritureCalcules = false;

    public function __construct(private readonly User $user) {}

    /**
     * Même règle que AbsenceService::peutModifier()/peutAnnuler() (PerimetreEmploye::peutModifier),
     * sans requête par ligne.
     */
    private function dansPerimetreEcriture(?Employe $employe): bool
    {
        if ($employe === null || $employe->organization_id !== $this->user->organization_id) {
            return false;
        }
        if (! $this->sitesEcritureCalcules) {
            $this->sitesEcriture = $this->user->agitSurToutesLesAgences() ? null : app(SiteScopeService::class)->assignedSiteIds($this->user);
            $this->sitesEcritureCalcules = true;
        }

        return $this->sitesEcriture === null || ($employe->site_id !== null && $this->sitesEcriture->contains($employe->site_id));
    }

    /** @return array<string, mixed> */
    public function ligne(Absence $absence): array
    {
        $employe = $absence->employe;
        $etat = $absence->etat();

        return [
            'id' => $absence->id,
            'employe_id' => $absence->employe_id,
            'salarie' => $employe?->nom_complet ?? '—',
            'matricule' => $employe?->matricule,
            'agence' => $employe?->site ? $employe->site->nom : null,
            'motif_id' => $absence->motif_absence_id,
            'motif' => $absence->motif?->libelle ?? '—',
            'date_debut' => $absence->date_debut->toDateString(),
            'date_fin' => $absence->date_fin->toDateString(),
            'date_debut_label' => $absence->date_debut->format('d/m/Y'),
            'date_fin_label' => $absence->date_fin->format('d/m/Y'),
            'duree_jours' => $absence->duree(),
            'commentaire' => $absence->commentaire,
            'a_justificatif' => $absence->justificatif_path !== null,
            'justificatif_nom' => $absence->justificatif_nom_original,
            'statut' => $absence->statut->value,
            'etat' => $etat,
            'etat_label' => Absence::libelleEtat($etat),
            'auteur' => $absence->auteur?->name,
            'saisie_le' => $absence->created_at?->format('d/m/Y H:i'),
            'peut_modifier' => ! $absence->estAnnulee() && $this->user->can('rh-absences.update') && $this->dansPerimetreEcriture($employe),
            'peut_annuler' => ! $absence->estAnnulee() && $this->user->can('rh-absences.annuler') && $this->dansPerimetreEcriture($employe),
        ];
    }

    /**
     * Détail (fenêtre « Absence ») : la ligne, plus la traçabilité de modification et d'annulation.
     *
     * @return array<string, mixed>
     */
    public function detail(Absence $absence): array
    {
        $absence->loadMissing([...self::RELATIONS, 'modificateur:id,personne_id', 'modificateur.personne:id,nom,prenom', 'annulateur:id,personne_id', 'annulateur.personne:id,nom,prenom']);

        return [
            ...$this->ligne($absence),
            'modifie_par' => $absence->modifie_par ? $absence->modificateur?->name : null,
            'modifie_le' => $absence->modifie_par ? $absence->updated_at?->format('d/m/Y H:i') : null,
            'annule_par' => $absence->annulateur?->name,
            'annule_le' => $absence->annule_le?->format('d/m/Y H:i'),
            'motif_annulation' => $absence->motif_annulation,
        ];
    }

    /**
     * Motifs proposés à la saisie : actifs seulement, dans l'ordre du référentiel.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function motifsSaisissables(string $organizationId): array
    {
        return MotifAbsence::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->ordonnes()
            ->get(['id', 'libelle'])
            ->map(fn (MotifAbsence $m) => ['value' => $m->id, 'label' => $m->libelle])
            ->values()
            ->all();
    }

    /**
     * Salariés pour qui l'utilisateur peut saisir une absence : ses agences de rattachement (toutes
     * pour un administrateur ou un superviseur), sortis compris pour régulariser une absence passée.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function salariesSaisissables(User $user): array
    {
        if (! $user->can('rh-absences.create')) {
            return [];
        }

        return Employe::with('personne')
            ->where('organization_id', $user->organization_id)
            ->when(! $user->agitSurToutesLesAgences(), fn ($q) => $q->whereIn('site_id', app(SiteScopeService::class)->assignedSiteIds($user)))
            ->get(['id', 'personne_id', 'matricule', 'statut'])
            ->sortBy(fn (Employe $e) => mb_strtolower($e->nom_complet))
            ->map(fn (Employe $e) => [
                'value' => $e->id,
                'label' => trim($e->nom_complet.($e->matricule ? " ({$e->matricule})" : '').($e->statut === StatutEmploye::SORTI ? ' — sorti' : '')),
            ])
            ->values()
            ->all();
    }
}
