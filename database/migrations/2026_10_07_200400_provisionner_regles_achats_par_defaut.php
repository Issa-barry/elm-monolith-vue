<?php

use App\Models\Organization;
use App\Models\RegleValidationRole;
use Illuminate\Database\Migrations\Migration;

/**
 * Migration de DONNÉES — continuité du module Achats (ADR 0021). Le périmètre « Peut acheter
 * pour » des règles de rôle gouverne la création, la lecture et la validation, sans passe-droit :
 * sans règle, plus personne ne pourrait créer ni voir un bon de commande. Chaque organisation
 * existante reçoit donc, pour admin_entreprise et super_admin, une règle « toutes agences, sans
 * limite » (décision du 07/10/2026), modifiable ensuite dans Paramètres → Achats.
 *
 * Non destructive et idempotente : une règle déjà configurée pour ces rôles n'est pas modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->select('id')->chunkById(200, function ($organizations) {
            foreach ($organizations as $org) {
                RegleValidationRole::provisionnerAchatsParDefaut($org->id);
            }
        });
    }

    /** Pas de rollback de données : les règles ont pu être reconfigurées depuis. */
    public function down(): void
    {
        //
    }
};
