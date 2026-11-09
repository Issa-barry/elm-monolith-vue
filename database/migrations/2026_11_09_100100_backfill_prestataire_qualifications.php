<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Migration de DONNÉES (ADR 0060, lot 6b, décision E) — reprise des qualifications : une ligne de
 * `prestataire_sous_categories` par prestataire, d'après son `type` (machiniste, mécanicien,
 * consultant), prestataires supprimés logiquement compris (historique). N'ajoute que des lignes :
 * `type` n'est pas modifié, aucune qualification n'est retirée. Rejouable sans doublon (index unique
 * (prestataire, sous-catégorie)). Une organisation sans référentiel reçoit d'abord les trois
 * sous-catégories avec les valeurs du lot 1 (figées ici). Les lectures basculent sur la table de
 * liaison avec le code du lot 6b ; le repli sur `type` (Prestataire::qualifieConsultant) couvre le
 * seul intervalle entre le déploiement du code et cette migration.
 */
return new class extends Migration
{
    private const QUALIFICATIONS = [
        'machiniste' => ['Machiniste', false, 1],
        'mecanicien' => ['Mécanicien', false, 2],
        'consultant' => ['Consultant', true, 3],
    ];

    public function up(): void
    {
        $maintenant = now();

        DB::table('prestataires')->whereIn('type', array_keys(self::QUALIFICATIONS))
            ->distinct()->pluck('organization_id')
            ->each(function (string $organizationId) use ($maintenant) {
                DB::table('contact_sous_categories')->insertOrIgnore(array_map(fn (string $code, array $q) => [
                    'id' => strtolower((string) Str::ulid()),
                    'organization_id' => $organizationId,
                    'categorie' => 'prestataire',
                    'code' => $code,
                    'libelle' => $q[0],
                    'is_system' => $q[1],
                    'is_active' => true,
                    'ordre' => $q[2],
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ], array_keys(self::QUALIFICATIONS), self::QUALIFICATIONS));
            });

        DB::table('prestataires')
            ->whereIn('type', array_keys(self::QUALIFICATIONS))
            ->select(['id', 'organization_id', 'type'])
            ->chunkById(500, function ($prestataires) use ($maintenant) {
                $referentiel = DB::table('contact_sous_categories')
                    ->where('categorie', 'prestataire')
                    ->whereIn('organization_id', $prestataires->pluck('organization_id')->unique())
                    ->whereIn('code', array_keys(self::QUALIFICATIONS))
                    ->get(['id', 'organization_id', 'code'])
                    ->keyBy(fn ($s) => "{$s->organization_id}:{$s->code}");

                $lignes = [];
                foreach ($prestataires as $p) {
                    $sousCategorie = $referentiel["{$p->organization_id}:{$p->type}"] ?? null;
                    if ($sousCategorie !== null) {
                        $lignes[] = [
                            'id' => strtolower((string) Str::ulid()),
                            'prestataire_id' => $p->id,
                            'contact_sous_categorie_id' => $sousCategorie->id,
                            'created_by' => null,
                            'created_at' => $maintenant,
                            'updated_at' => $maintenant,
                        ];
                    }
                }
                DB::table('prestataire_sous_categories')->insertOrIgnore($lignes);
            });

        $sansQualification = DB::table('prestataires')
            ->whereNotExists(fn ($s) => $s->from('prestataire_sous_categories')->whereColumn('prestataire_sous_categories.prestataire_id', 'prestataires.id'))
            ->count();
        if ($sansQualification > 0) {
            Log::warning('Reprise des qualifications prestataire : prestataires sans qualification', ['nombre' => $sansQualification]);
        }
    }

    /** Pas de retrait : ces lignes sont désormais la source de vérité des qualifications. */
    public function down(): void
    {
        //
    }
};
