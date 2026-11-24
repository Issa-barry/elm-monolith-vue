<?php

namespace App\Support\Rh;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Motifs d'absence créés pour chaque organisation (docs/rh-absences.md), renommables et
 * désactivables ensuite dans Paramètres → RH → Motifs d'absence. Rejouable sans doublon : l'index
 * unique (organization_id, code) écarte un motif déjà présent, même renommé ou désactivé. Les
 * organisations antérieures sont initialisées par la migration 2026_11_23_100200.
 */
final class MotifsAbsenceParDefaut
{
    /** @var array<string, string> code => libellé, dans l'ordre d'affichage */
    public const MOTIFS = [
        'maladie' => 'Maladie',
        'rendez_vous_medical' => 'Rendez-vous médical',
        'absence_autorisee' => 'Absence autorisée',
        'absence_injustifiee' => 'Absence injustifiée',
        'motif_familial' => 'Motif familial',
        'motif_personnel' => 'Motif personnel',
        'autre' => 'Autre',
    ];

    /** @return int nombre de motifs créés */
    public static function initialiserPour(string $organizationId): int
    {
        $maintenant = now();
        $crees = 0;
        $ordre = 0;

        foreach (self::MOTIFS as $code => $libelle) {
            $crees += DB::table('motifs_absence')->insertOrIgnore([
                'id' => strtolower((string) Str::ulid()),
                'organization_id' => $organizationId,
                'code' => $code,
                'libelle' => $libelle,
                'is_active' => true,
                'ordre' => ++$ordre,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ]);
        }

        return $crees;
    }
}
