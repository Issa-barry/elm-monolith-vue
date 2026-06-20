<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_site_scopes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role_name');
            $table->string('module'); // produits_stock, depenses, ventes, factures, logistique, commissions, rh
            $table->string('scope_type')->default('own_site'); // all_sites | own_site | selected_sites
            $table->json('sites')->nullable(); // uniquement si scope_type = 'selected_sites'
            $table->boolean('is_actif')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'role_name', 'module']);
            $table->index(['organization_id', 'role_name']);
            $table->index(['organization_id', 'module', 'is_actif']);
        });

        // Migrer les données existantes de droit_ajustement_stocks → role_site_scopes
        if (Schema::hasTable('droit_ajustement_stocks')) {
            $droits = DB::table('droit_ajustement_stocks')->get();

            foreach ($droits as $droit) {
                $scopeType = match ($droit->perimetre) {
                    'toutes' => 'all_sites',
                    'sites_specifiques' => 'selected_sites',
                    default => 'own_site',
                };

                DB::table('role_site_scopes')->insertOrIgnore([
                    'id' => Str::ulid(),
                    'organization_id' => $droit->organization_id,
                    'role_name' => $droit->role_name,
                    'module' => 'produits_stock',
                    'scope_type' => $scopeType,
                    'sites' => $droit->sites,
                    'is_actif' => $droit->is_actif,
                    'created_at' => $droit->created_at,
                    'updated_at' => $droit->updated_at,
                ]);
            }

            Schema::dropIfExists('droit_ajustement_stocks');
        }
    }

    public function down(): void
    {
        Schema::create('droit_ajustement_stocks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('role_name');
            $table->string('perimetre')->default('toutes');
            $table->json('sites')->nullable();
            $table->boolean('is_actif')->default(true);
            $table->timestamps();
        });

        Schema::dropIfExists('role_site_scopes');
    }
};
