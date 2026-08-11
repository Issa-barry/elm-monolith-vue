<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('depenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('depense_type_id')->constrained('depense_types')->restrictOnDelete();
            $table->string('beneficiaire_type', 20)->nullable();
            $table->ulid('beneficiaire_id')->nullable();

            $table->decimal('montant', 12, 2);
            $table->date('date_depense');
            $table->text('commentaire')->nullable();
            $table->string('commentaire_rejet', 255)->nullable();

            $table->string('statut')->default('brouillon'); // brouillon | soumis | valide | rejete | annule
            $table->foreignUlid('validateur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_validation')->nullable();
            $table->text('motif_rejet')->nullable();
            $table->string('justificatif_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'statut', 'date_depense']);
            $table->index(['organization_id', 'depense_type_id']);
            $table->index(['organization_id', 'beneficiaire_type', 'beneficiaire_id'], 'dep_org_ben_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depenses');
    }
};
