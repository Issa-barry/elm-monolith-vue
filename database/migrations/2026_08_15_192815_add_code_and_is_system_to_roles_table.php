<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prépare le CRUD de rôles :
 *  - `code` : trinôme/abréviation métier (ex: "PDG", "DG"), purement informatif — jamais lu par
 *    une Policy/Gate, aucune logique n'en dépend. Laissé null pour les rôles déjà en base ; la
 *    valeur est un choix métier, pas quelque chose que cette migration doit deviner.
 *  - `is_system` : distingue les rôles créés par RolesAndPermissionsSeeder (protégés — jamais
 *    renommables ni supprimables, cf. RoleController) des rôles créés ensuite via le CRUD par un
 *    client (renommables/supprimables tant qu'aucun utilisateur n'y est rattaché). Sans cette
 *    colonne, un CRUD de rôles pourrait supprimer 'manager' ou 'comptable' et casser le
 *    middleware `role:` des routes (routes/web.php) qui les nomme en dur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->after('name');
            $table->boolean('is_system')->default(false)->after('code');
        });

        DB::table('roles')
            ->whereIn('name', [
                'super_admin', 'admin_entreprise', 'manager',
                'commerciale', 'comptable', 'client', 'proprietaire', 'livreur',
            ])
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['code', 'is_system']);
        });
    }
};
