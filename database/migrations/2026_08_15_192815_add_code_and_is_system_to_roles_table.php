<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prépare le CRUD de rôles en self-service, par organisation :
 *  - `label` : nom affiché, librement modifiable — distinct de `name` (technique Spatie, généré
 *    une seule fois à la création et jamais réécrit ensuite, cf. RoleNamingService). Backfillé à
 *    `name` pour les rôles déjà en base, puis affiné par RolesAndPermissionsSeeder pour les 8
 *    rôles système.
 *  - `code` : trinôme/abréviation métier (ex: "PDG"), unique par organisation.
 *  - `organization_id` : null pour les 8 rôles système partagés (créés par
 *    RolesAndPermissionsSeeder, jamais dupliqués) ; rempli pour tout rôle créé via le CRUD
 *    (RoleController) — chaque organisation a ses propres rôles métier, jamais partagés entre
 *    elles (la table `roles` de Spatie est globale par défaut, sans cette colonne deux
 *    organisations SaaS se partageraient silencieusement les mêmes rôles/permissions).
 *
 * "Rôle système protégé" n'est plus une colonne (l'ancien `is_system`, retiré) : c'est désormais
 * une règle unique, centralisée dans RoleController — `name === 'super_admin'` — cf. sa docblock.
 *
 * La contrainte unique d'origine de Spatie (`name`, `guard_name`, globale) est remplacée par une
 * contrainte scopée par organisation : deux organisations peuvent avoir un rôle de même nom
 * technique, jamais deux fois la même organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable()->after('name');
            $table->string('code', 10)->nullable()->after('label');
        });

        // Backfill universel avant que RolesAndPermissionsSeeder n'affine les 8 rôles système
        // avec un libellé français propre — évite qu'un rôle déjà en base reste sans libellé.
        DB::table('roles')->update(['label' => DB::raw('name')]);

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_name_guard_name_unique');
            $table->unique(['organization_id', 'name', 'guard_name'], 'roles_org_name_guard_unique');
            $table->unique(['organization_id', 'code'], 'roles_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_org_code_unique');
            $table->dropUnique('roles_org_name_guard_unique');
            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['label', 'code']);
        });
    }
};
