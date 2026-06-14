<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depense_types', function (Blueprint $table) {
            $table->string('categorie', 20)->default('interne')->after('description');
            $table->boolean('commentaire_obligatoire')->default(false)->after('categorie');
            $table->boolean('justificatif_obligatoire')->default(false)->after('commentaire_obligatoire');
        });

        // Migrate existing flags to new categorie field
        DB::statement("UPDATE depense_types SET categorie = 'vehicule' WHERE requires_vehicle = 1 AND (deleted_at IS NULL OR deleted_at IS NOT NULL)");
        DB::statement("UPDATE depense_types SET categorie = 'employe' WHERE applique_aux_employes = 1 AND requires_vehicle = 0 AND (deleted_at IS NULL OR deleted_at IS NOT NULL)");
        DB::statement('UPDATE depense_types SET commentaire_obligatoire = requires_comment');

        Schema::table('depense_types', function (Blueprint $table) {
            $table->dropColumn(['requires_vehicle', 'requires_comment', 'applique_aux_employes']);
        });

        Schema::table('depense_types', function (Blueprint $table) {
            $table->index(['organization_id', 'categorie', 'is_active'], 'dt_org_cat_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('depense_types', function (Blueprint $table) {
            $table->dropIndex('dt_org_cat_active_idx');
            $table->boolean('requires_vehicle')->default(false);
            $table->boolean('requires_comment')->default(false);
            $table->boolean('applique_aux_employes')->default(false);
            $table->dropColumn(['categorie', 'commentaire_obligatoire', 'justificatif_obligatoire']);
        });
    }
};
