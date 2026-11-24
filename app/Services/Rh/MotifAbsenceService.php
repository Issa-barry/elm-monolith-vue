<?php

namespace App\Services\Rh;

use App\Models\MotifAbsence;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Création, renommage, activation et désactivation des motifs d'absence, strictement dans
 * l'organisation donnée (docs/rh-absences.md). Libellé unique par organisation, sans tenir compte
 * de la casse ; code technique déduit du libellé à la création, puis figé.
 */
class MotifAbsenceService
{
    public function creer(string $libelle, string $organizationId): MotifAbsence
    {
        $libelle = $this->libelleValide($libelle, $organizationId);

        return MotifAbsence::create([
            'organization_id' => $organizationId,
            'code' => $this->codeUnique($libelle, $organizationId),
            'libelle' => $libelle,
            'is_active' => true,
            'ordre' => (int) MotifAbsence::where('organization_id', $organizationId)->max('ordre') + 1,
        ]);
    }

    public function renommer(MotifAbsence $motif, string $libelle): MotifAbsence
    {
        $motif->update(['libelle' => $this->libelleValide($libelle, $motif->organization_id, $motif->id)]);

        return $motif;
    }

    /** Désactivé : plus proposé à la saisie ; les absences existantes gardent leur motif. */
    public function basculer(MotifAbsence $motif): MotifAbsence
    {
        $motif->update(['is_active' => ! $motif->is_active]);

        return $motif;
    }

    private function libelleValide(string $libelle, string $organizationId, ?string $ignorerId = null): string
    {
        $libelle = trim(preg_replace('/\s+/u', ' ', $libelle) ?? '');
        if ($libelle === '') {
            throw ValidationException::withMessages(['libelle' => 'Le libellé est obligatoire.']);
        }

        $existe = MotifAbsence::where('organization_id', $organizationId)
            ->when($ignorerId !== null, fn ($q) => $q->whereKeyNot($ignorerId))
            ->get(['id', 'libelle'])
            ->contains(fn (MotifAbsence $m) => mb_strtolower($m->libelle) === mb_strtolower($libelle));

        if ($existe) {
            throw ValidationException::withMessages(['libelle' => "Un motif portant ce libellé existe déjà (« {$libelle} »)."]);
        }

        return $libelle;
    }

    private function codeUnique(string $libelle, string $organizationId): string
    {
        $base = Str::limit(Str::slug($libelle, '_'), 34, '') ?: 'motif';
        $code = $base;
        $suffixe = 1;
        while (MotifAbsence::where('organization_id', $organizationId)->where('code', $code)->exists()) {
            $code = $base.'_'.(++$suffixe);
        }

        return $code;
    }
}
