<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migration de DONNÉES (ADR 0070) — crée les sept motifs d'absence par défaut dans chaque
 * organisation existante. Rejouable sans doublon : l'index unique (organization_id, code) écarte un
 * motif déjà présent, même renommé ou désactivé depuis. Les organisations créées ensuite les
 * reçoivent par Organization::booted() (MotifsAbsenceParDefaut::initialiserPour()). Valeurs figées
 * ici pour que la migration produise toujours le même résultat.
 */
return new class extends Migration
{
    private const MOTIFS = [
        'maladie' => 'Maladie',
        'rendez_vous_medical' => 'Rendez-vous médical',
        'absence_autorisee' => 'Absence autorisée',
        'absence_injustifiee' => 'Absence injustifiée',
        'motif_familial' => 'Motif familial',
        'motif_personnel' => 'Motif personnel',
        'autre' => 'Autre',
    ];

    public function up(): void
    {
        $maintenant = now();

        DB::table('organizations')->orderBy('id')->pluck('id')->each(function (string $organizationId) use ($maintenant) {
            $ordre = 0;
            foreach (self::MOTIFS as $code => $libelle) {
                DB::table('motifs_absence')->insertOrIgnore([
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
        });
    }

    /** Pas de retrait de données : la table elle-même est retirée par la migration de schéma. */
    public function down(): void
    {
        //
    }
};
