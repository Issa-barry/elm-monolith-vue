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

            return Contrat::create([
                ...$data,
                'organization_id' => $organizationId,
                'statut_contrat' => StatutContrat::ACTIF->value,
            ]);
        });
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
