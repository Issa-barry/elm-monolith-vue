<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Recréer droit_ajustement_stocks ───────────────────────────────────
        Schema::create('droit_ajustement_stocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role_name');
            $table->string('perimetre')->default('toutes_agences'); // toutes_agences | agences_selectionnees
            $table->json('sites')->nullable(); // liste d'IDs si perimetre = agences_selectionnees
            $table->boolean('is_actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'role_name']);
            $table->index(['organization_id', 'is_actif']);
        });

        // Migrer les données existantes depuis role_site_scopes (module = produits_stock)
        if (Schema::hasTable('role_site_scopes')) {
            $scopes = DB::table('role_site_scopes')
                ->where('module', 'produits_stock')
                ->get();

            foreach ($scopes as $scope) {
                $perimetre = $scope->scope_type === 'selected_sites'
                    ? 'agences_selectionnees'
                    : 'toutes_agences';

                DB::table('droit_ajustement_stocks')->insertOrIgnore([
                    'id' => \Illuminate\Support\Str::ulid(),
                    'organization_id' => $scope->organization_id,
                    'role_name' => $scope->role_name,
                    'perimetre' => $perimetre,
                    'sites' => $scope->sites,
                    'is_actif' => $scope->is_actif,
                    'created_at' => $scope->created_at,
                    'updated_at' => $scope->updated_at,
                ]);
            }

            Schema::dropIfExists('role_site_scopes');
        }

        // ── Créer droit_creation_depenses ─────────────────────────────────────
        Schema::create('droit_creation_depenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role_name');
            $table->string('perimetre')->default('toutes_agences'); // toutes_agences | agences_selectionnees
            $table->json('sites')->nullable();
            $table->boolean('is_actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'role_name']);
            $table->index(['organization_id', 'is_actif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('droit_ajustement_stocks');
        Schema::dropIfExists('droit_creation_depenses');
    }
};
