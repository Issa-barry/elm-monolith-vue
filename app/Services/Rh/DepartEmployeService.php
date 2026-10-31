<?php

namespace App\Services\Rh;

use App\Enums\StatutContrat;
use App\Enums\StatutEmploye;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\User;
use App\Services\Rh\Paie\CalendrierPaieService;
use App\Services\SiteScopeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Départ effectif d'un salarié (docs/rh-depart-salarie.md) : seul chemin qui passe un salarié en
 * « Sorti ». La date de sortie borne la période d'emploi lue par la paie ; le contrat actif est
 * terminé à cette date (sa date de fin d'origine est conservée pour l'annulation). Aucune date ne
 * peut toucher un mois de paie figé (ADR 0039, ADR 0040).
 */
class DepartEmployeService
{
    public function __construct(
        private readonly ContratService $contrats,
        private readonly CalendrierPaieService $calendrier,
    ) {}

    /**
     * Enregistrer ou annuler un départ : modifier l'employé ET ses contrats (le contrat actif est
     * terminé), même organisation (vérifiée ici : le Gate::before du super administrateur passe les
     * policies) et salarié d'une agence de rattachement, sauf profil sans restriction d'agence.
     */
    public static function peutGerer(User $user, Employe $employe): bool
    {
        if ($user->organization_id !== $employe->organization_id
            || ! $user->can('rh-employes.update') || ! $user->can('rh-contrats.update')) {
            return false;
        }

        if ($user->agitSurToutesLesAgences()) {
            return true;
        }

        return $employe->site_id !== null
            && app(SiteScopeService::class)->assignedSiteIds($user)->contains($employe->site_id);
    }

    public function enregistrer(Employe $employe, Carbon $dateSortie, string $motif): Employe
    {
        return DB::transaction(function () use ($employe, $dateSortie, $motif) {
            $employe = $this->contrats->verrouillerEmploye($employe->id, $employe->organization_id);
            $dateSortie = $dateSortie->copy()->startOfDay();
            $this->verifierDateSortie($employe, $dateSortie);

            $contrat = Contrat::where('employe_id', $employe->id)
                ->where('statut_contrat', StatutContrat::ACTIF->value)
                ->lockForUpdate()
                ->first();

            if ($contrat !== null) {
                if ($dateSortie->lt($contrat->date_debut)) {
                    throw ValidationException::withMessages([
                        'date_sortie' => 'La date de sortie précède le début du contrat en cours ('.$contrat->date_debut->format('d/m/Y').').',
                    ]);
                }

                $finPrevue = $contrat->date_fin;
                $contrat->forceFill([
                    'termine_par_depart' => true,
                    'date_fin_avant_depart' => $finPrevue,
                    // Un contrat déjà échu avant la sortie garde sa date de fin.
                    'date_fin' => $finPrevue !== null && $finPrevue->lt($dateSortie) ? $finPrevue : $dateSortie,
                    'statut_contrat' => StatutContrat::TERMINE->value,
                ])->save();
            }

            $employe->update([
                'statut' => StatutEmploye::SORTI->value,
                'date_sortie' => $dateSortie->toDateString(),
                'motif_sortie' => trim($motif),
            ]);

            return $employe;
        });
    }

    private function verifierDateSortie(Employe $employe, Carbon $dateSortie): void
    {
        if ($employe->statut === StatutEmploye::SORTI) {
            throw ValidationException::withMessages(['date_sortie' => 'Le départ de ce salarié est déjà enregistré.']);
        }
        if ($dateSortie->gt(today())) {
            throw ValidationException::withMessages([
                'date_sortie' => 'La date de sortie ne peut pas être dans le futur : enregistrez le départ une fois qu’il est effectif.',
            ]);
        }
        if ($employe->date_embauche !== null && $dateSortie->lt($employe->date_embauche)) {
            throw ValidationException::withMessages([
                'date_sortie' => 'La date de sortie ne peut pas précéder la date d’embauche ('.$employe->date_embauche->format('d/m/Y').').',
            ]);
        }
        $this->calendrier->exigerModifiable($employe->organization_id, $dateSortie, 'date_sortie', 'le départ peut être daté');
    }

    /** Rétablit le salarié et le contrat terminé par son départ, tant que la paie n'en a rien figé. */
    public function annuler(Employe $employe): Employe
    {
        return DB::transaction(function () use ($employe) {
            $employe = $this->contrats->verrouillerEmploye($employe->id, $employe->organization_id);

            if ($employe->statut !== StatutEmploye::SORTI || $employe->date_sortie === null) {
                throw ValidationException::withMessages(['depart' => 'Aucun départ enregistré pour ce salarié.']);
            }

            $premier = $this->calendrier->premierJourModifiable($employe->organization_id);
            if ($premier !== null && $employe->date_sortie->lt($premier)) {
                throw ValidationException::withMessages([
                    'depart' => 'La paie du mois de sortie est déjà figée : ce départ ne peut plus être annulé.',
                ]);
            }

            $contrat = Contrat::where('employe_id', $employe->id)
                ->where('termine_par_depart', true)
                ->orderByDesc('date_debut')
                ->lockForUpdate()
                ->first();

            if ($contrat !== null) {
                if ($this->contrats->aUnAutreContratActif($employe->id, $contrat->id)) {
                    throw ValidationException::withMessages([
                        'depart' => 'Un autre contrat est actif pour ce salarié : le contrat terminé par le départ ne peut pas être rétabli.',
                    ]);
                }

                $contrat->forceFill([
                    'date_fin' => $contrat->date_fin_avant_depart,
                    'statut_contrat' => StatutContrat::ACTIF->value,
                    'termine_par_depart' => false,
                    'date_fin_avant_depart' => null,
                ])->save();
            }

            $employe->update([
                'statut' => StatutEmploye::ACTIF->value,
                'date_sortie' => null,
                'motif_sortie' => null,
            ]);

            return $employe;
        });
    }
}
