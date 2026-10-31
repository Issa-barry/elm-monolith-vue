<?php

namespace App\Services\Rh;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Models\Contrat;
use App\Models\Employe;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création d'un contrat de travail, commune à l'écran Contrats et à l'import des dossiers RH :
 * employé de l'organisation, un seul contrat actif par employé, contrôlé sous verrou de l'employé
 * (deux créations simultanées ne peuvent pas produire deux contrats actifs).
 */
class ContratService
{
    /**
     * @param  array{employe_id: string, type_contrat: string, date_debut: string, date_fin?: ?string, salaire_base?: float|string|null}  $data
     */
    public function creer(array $data, string $organizationId): Contrat
    {
        // CDI => date_fin null
        if ($data['type_contrat'] === TypeContrat::CDI->value) {
            $data['date_fin'] = null;
        }

        return DB::transaction(function () use ($data, $organizationId) {
            $employe = $this->verrouillerEmploye($data['employe_id'], $organizationId);
            $this->assertAucunAutreContratActif($employe->id);
            $this->assertPasDeChevauchement($employe->id, $data['date_debut'], $data['date_fin'] ?? null);

            // Date d'embauche du dossier : reprise du premier contrat quand elle n'est pas encore
            // connue (elle n'est plus saisie à la création de l'employé). Une date déjà renseignée
            // (ancienneté antérieure, import) n'est jamais modifiée ici.
            if ($employe->date_embauche === null) {
                $employe->update(['date_embauche' => $data['date_debut']]);
            }

            return Contrat::create([
                ...$data,
                'organization_id' => $organizationId,
                'statut_contrat' => StatutContrat::ACTIF->value,
            ]);
        });
    }

    /**
     * Deux contrats d'un même employé ne peuvent pas couvrir les mêmes jours : la paie les refuse
     * (anomalie bloquante « contrats_chevauchants »), la saisie les refuse donc dès l'enregistrement.
     * Un contrat terminé ou rompu sans date de fin n'a pas de période connue : il est ignoré,
     * comme dans la paie.
     */
    public function assertPasDeChevauchement(string $employeId, string $dateDebut, ?string $dateFin, ?string $saufContratId = null): void
    {
        $autre = Contrat::where('employe_id', $employeId)
            ->when($saufContratId, fn ($q) => $q->whereKeyNot($saufContratId))
            ->where(fn ($q) => $q->where('statut_contrat', StatutContrat::ACTIF->value)->orWhereNotNull('date_fin'))
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $dateDebut))
            ->when($dateFin !== null, fn ($q) => $q->whereDate('date_debut', '<=', $dateFin))
            ->orderBy('date_debut')
            ->lockForUpdate()
            ->first();

        if ($autre !== null) {
            $periode = 'du '.$autre->date_debut->format('d/m/Y').($autre->date_fin ? ' au '.$autre->date_fin->format('d/m/Y') : ', sans date de fin');
            throw ValidationException::withMessages([
                'date_debut' => "Ces dates chevauchent un autre contrat de cet employé ({$autre->type_contrat->label()} {$periode}).",
            ]);
        }
    }

    /** Employé de l'organisation, verrouillé jusqu'à la fin de la transaction (404 sinon). */
    public function verrouillerEmploye(string $employeId, string $organizationId): Employe
    {
        return Employe::whereKey($employeId)
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Un seul contrat actif par employé, en création comme en modification. */
    public function assertAucunAutreContratActif(string $employeId, ?string $saufContratId = null): void
    {
        if ($this->aUnAutreContratActif($employeId, $saufContratId)) {
            throw ValidationException::withMessages([
                'employe_id' => 'Cet employé a déjà un contrat actif. Terminez-le avant d\'en créer ou d\'en activer un autre.',
            ]);
        }
    }

    /**
     * Lecture VERROUILLANTE : sous MySQL REPEATABLE READ, une lecture simple dans une transaction déjà
     * commencée (import RH : lignes précédentes) lit l'instantané du début de la transaction et ne
     * verrait pas un contrat validé entre-temps par une autre opération. FOR UPDATE lit la dernière
     * version validée.
     */
    public function aUnAutreContratActif(string $employeId, ?string $saufContratId = null): bool
    {
        return Contrat::where('employe_id', $employeId)
            ->where('statut_contrat', StatutContrat::ACTIF->value)
            ->when($saufContratId, fn ($q) => $q->whereKeyNot($saufContratId))
            ->lockForUpdate()
            ->exists();
    }
}
