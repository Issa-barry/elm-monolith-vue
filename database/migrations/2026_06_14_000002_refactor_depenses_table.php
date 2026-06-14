<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->string('beneficiaire_type', 20)->nullable()->after('depense_type_id');
            $table->ulid('beneficiaire_id')->nullable()->after('beneficiaire_type');
            $table->foreignUlid('validateur_id')->nullable()->constrained('users')->nullOnDelete()->after('statut');
            $table->timestamp('date_validation')->nullable()->after('validateur_id');
            $table->text('motif_rejet')->nullable()->after('date_validation');
            $table->string('justificatif_path')->nullable()->after('motif_rejet');
        });

        // Migrate vehicule_id / employe_id → beneficiaire polymorphique
        DB::statement("UPDATE depenses SET beneficiaire_type = 'vehicule', beneficiaire_id = vehicule_id WHERE vehicule_id IS NOT NULL");
        DB::statement("UPDATE depenses SET beneficiaire_type = 'employe', beneficiaire_id = employe_id WHERE employe_id IS NOT NULL AND vehicule_id IS NULL");

        // 'approuve' → 'valide'
        DB::statement("UPDATE depenses SET statut = 'valide' WHERE statut = 'approuve'");

        Schema::table('depenses', function (Blueprint $table) {
            $table->dropForeign(['vehicule_id']);
            $table->dropForeign(['employe_id']);
            $table->dropColumn(['vehicule_id', 'employe_id']);
        });

        Schema::table('depenses', function (Blueprint $table) {
            $table->index(['organization_id', 'beneficiaire_type', 'beneficiaire_id'], 'dep_org_ben_idx');
        });
    }

    public function down(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->dropIndex('dep_org_ben_idx');
            $table->foreignUlid('vehicule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('employe_id')->nullable()->constrained()->nullOnDelete();
            $table->dropColumn(['beneficiaire_type', 'beneficiaire_id', 'validateur_id', 'date_validation', 'motif_rejet', 'justificatif_path']);
        });
    }
};
